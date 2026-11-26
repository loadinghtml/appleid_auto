import ipaddress
import logging
import select
import socket
import socketserver
import struct
import threading
from urllib.parse import quote, unquote, urlparse

logger = logging.getLogger(__name__)


def parse_socks5_proxy(proxy_content: str):
    """Parse a SOCKS5 proxy string into (host, port, username, password).

    Supported formats:
    - host:port
    - user:pass@host:port
    - socks5://user:pass@host:port
    - host:port:user:pass
    """
    raw = (proxy_content or "").strip()
    if not raw:
        raise ValueError("Empty proxy content")

    for prefix in ("socks5h://", "socks5://"):
        if raw.lower().startswith(prefix):
            raw = raw[len(prefix):]
            break

    if "@" not in raw and raw.count(":") == 3:
        parts = raw.split(":")
        if parts[1].isdigit():
            host, port_str, user, password = parts
            raw = f"{quote(user, safe='')}:{quote(password, safe='')}@{host}:{port_str}"

    parsed = urlparse(f"socks5://{raw}")
    if not parsed.hostname or not parsed.port:
        raise ValueError(f"Invalid SOCKS5 proxy format: {proxy_content}")

    username = unquote(parsed.username) if parsed.username is not None else ""
    password = unquote(parsed.password) if parsed.password is not None else ""
    return parsed.hostname, parsed.port, username, password


def get_bridge_advertise_host(webdriver_url: str) -> str:
    """Determine the IP address that the webdriver container can use to reach this process."""
    if not webdriver_url or webdriver_url == "local":
        return "127.0.0.1"

    parsed = urlparse(webdriver_url)
    target_host = parsed.hostname
    if not target_host:
        return "127.0.0.1"

    target_port = parsed.port or (443 if parsed.scheme == "https" else 80)
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.connect((target_host, target_port))
            local_ip = sock.getsockname()[0]
            if local_ip and local_ip != "0.0.0.0":
                return local_ip
    except OSError as exc:
        logger.warning(f"Failed to detect outbound IP for webdriver {webdriver_url}: {exc}")
    return "127.0.0.1"


def _recv_exact(sock: socket.socket, size: int) -> bytes:
    chunks = bytearray()
    while len(chunks) < size:
        data = sock.recv(size - len(chunks))
        if not data:
            raise ConnectionError("Unexpected EOF from socket")
        chunks.extend(data)
    return bytes(chunks)


def _connect_via_socks5(
    socks_host: str,
    socks_port: int,
    username: str,
    password: str,
    target_host: str,
    target_port: int,
    timeout: float = 15.0,
) -> socket.socket:
    upstream = socket.create_connection((socks_host, socks_port), timeout=timeout)
    try:
        if username or password:
            upstream.sendall(b"\x05\x02\x00\x02")
        else:
            upstream.sendall(b"\x05\x01\x00")

        ver, method = _recv_exact(upstream, 2)
        if ver != 0x05:
            raise ConnectionError(f"Unsupported SOCKS version: {ver}")

        if method == 0x02:
            if not (username or password):
                raise PermissionError("SOCKS5 server requires authentication")
            user_bytes = username.encode("utf-8")
            pass_bytes = password.encode("utf-8")
            if len(user_bytes) > 255 or len(pass_bytes) > 255:
                raise ValueError("SOCKS5 username or password exceeds 255 bytes")
            auth_req = (
                b"\x01"
                + bytes([len(user_bytes)])
                + user_bytes
                + bytes([len(pass_bytes)])
                + pass_bytes
            )
            upstream.sendall(auth_req)
            auth_ver, auth_status = _recv_exact(upstream, 2)
            if auth_ver != 0x01 or auth_status != 0x00:
                raise PermissionError(f"SOCKS5 authentication failed with status {auth_status}")
        elif method == 0xFF:
            raise PermissionError("SOCKS5 server rejected all authentication methods")
        elif method != 0x00:
            raise ConnectionError(f"Unsupported SOCKS5 auth method: {method}")

        addr_payload = _encode_socks5_address(target_host)
        connect_req = b"\x05\x01\x00" + addr_payload + struct.pack("!H", target_port)
        upstream.sendall(connect_req)

        resp_header = _recv_exact(upstream, 4)
        if resp_header[0] != 0x05:
            raise ConnectionError(f"Invalid SOCKS5 reply version: {resp_header[0]}")
        if resp_header[1] != 0x00:
            raise ConnectionError(f"SOCKS5 connect failed with reply code {resp_header[1]}")

        atyp = resp_header[3]
        if atyp == 0x01:
            _recv_exact(upstream, 4)
        elif atyp == 0x03:
            domain_len = _recv_exact(upstream, 1)[0]
            _recv_exact(upstream, domain_len)
        elif atyp == 0x04:
            _recv_exact(upstream, 16)
        else:
            raise ConnectionError(f"Unknown SOCKS5 address type: {atyp}")
        _recv_exact(upstream, 2)

        upstream.settimeout(None)
        return upstream
    except BaseException:
        upstream.close()
        raise


def _encode_socks5_address(host: str) -> bytes:
    cleaned = host.strip("[]")
    try:
        ip_obj = ipaddress.ip_address(cleaned)
        if isinstance(ip_obj, ipaddress.IPv4Address):
            return b"\x01" + ip_obj.packed
        return b"\x04" + ip_obj.packed
    except ValueError:
        host_bytes = cleaned.encode("idna")
        if len(host_bytes) > 255:
            raise ValueError(f"Target hostname too long: {host}")
        return b"\x03" + bytes([len(host_bytes)]) + host_bytes


def _relay_bidirectional(client_sock: socket.socket, upstream_sock: socket.socket):
    client_sock.settimeout(None)
    upstream_sock.settimeout(None)
    sockets = [client_sock, upstream_sock]
    while True:
        readable, _, exceptional = select.select(sockets, [], sockets, 60.0)
        if exceptional:
            break
        if not readable:
            continue
        for sock in readable:
            other = upstream_sock if sock is client_sock else client_sock
            data = sock.recv(65536)
            if not data:
                return
            other.sendall(data)


def _split_host_port(authority: str, default_port: int):
    authority = authority.strip()
    if authority.startswith("["):
        end_bracket = authority.find("]")
        if end_bracket == -1:
            raise ValueError(f"Invalid IPv6 host: {authority}")
        host = authority[1:end_bracket]
        rest = authority[end_bracket + 1:]
        port = int(rest[1:]) if rest.startswith(":") else default_port
        return host, port
    if ":" in authority:
        host, port_str = authority.rsplit(":", 1)
        if port_str.isdigit():
            return host, int(port_str)
    return authority, default_port


class _ThreadedHTTPServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


class _ProxyRequestHandler(socketserver.BaseRequestHandler):
    def handle(self):
        client_sock = self.request
        client_sock.settimeout(15.0)
        upstream_sock = None
        try:
            buffer = bytearray()
            while b"\r\n\r\n" not in buffer:
                chunk = client_sock.recv(8192)
                if not chunk:
                    return
                buffer.extend(chunk)
                if len(buffer) > 65536:
                    client_sock.sendall(b"HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n")
                    return

            header_end = buffer.index(b"\r\n\r\n")
            header_bytes = bytes(buffer[:header_end])
            remainder = bytes(buffer[header_end + 4:])

            lines = header_bytes.decode("latin-1").split("\r\n")
            if not lines or not lines[0]:
                return
            parts = lines[0].split(" ", 2)
            if len(parts) != 3:
                client_sock.sendall(b"HTTP/1.1 400 Bad Request\r\n\r\n")
                return
            method, target, version = parts

            bridge = self.server.bridge
            if method.upper() == "CONNECT":
                target_host, target_port = _split_host_port(target, 443)
                upstream_sock = _connect_via_socks5(
                    bridge.socks_host,
                    bridge.socks_port,
                    bridge.username,
                    bridge.password,
                    target_host,
                    target_port,
                )
                client_sock.sendall(b"HTTP/1.1 200 Connection Established\r\n\r\n")
                if remainder:
                    upstream_sock.sendall(remainder)
                _relay_bidirectional(client_sock, upstream_sock)
            else:
                parsed = urlparse(target)
                if parsed.hostname:
                    target_host = parsed.hostname
                    target_port = parsed.port or (443 if parsed.scheme == "https" else 80)
                    path = parsed.path or "/"
                    if parsed.query:
                        path = f"{path}?{parsed.query}"
                else:
                    host_header = ""
                    for line in lines[1:]:
                        if line.lower().startswith("host:"):
                            host_header = line.split(":", 1)[1].strip()
                            break
                    if not host_header:
                        client_sock.sendall(b"HTTP/1.1 400 Bad Request\r\n\r\n")
                        return
                    target_host, target_port = _split_host_port(host_header, 80)
                    path = target

                upstream_sock = _connect_via_socks5(
                    bridge.socks_host,
                    bridge.socks_port,
                    bridge.username,
                    bridge.password,
                    target_host,
                    target_port,
                )
                filtered_headers = [
                    line for line in lines[1:]
                    if not line.lower().startswith("proxy-connection:")
                    and not line.lower().startswith("proxy-authorization:")
                ]
                rebuilt = f"{method} {path} {version}\r\n" + "\r\n".join(filtered_headers) + "\r\n\r\n"
                upstream_sock.sendall(rebuilt.encode("latin-1"))
                if remainder:
                    upstream_sock.sendall(remainder)
                _relay_bidirectional(client_sock, upstream_sock)
        except PermissionError as exc:
            logger.error(f"SOCKS5 proxy auth error: {exc}")
            try:
                client_sock.sendall(b"HTTP/1.1 407 Proxy Authentication Required\r\n\r\n")
            except OSError:
                pass
        except BaseException as exc:
            logger.debug(f"Local SOCKS5 bridge connection closed: {exc}")
            try:
                client_sock.sendall(b"HTTP/1.1 502 Bad Gateway\r\n\r\n")
            except OSError:
                pass
        finally:
            if upstream_sock is not None:
                try:
                    upstream_sock.close()
                except OSError:
                    pass


class LocalSocks5Bridge:
    """Local HTTP proxy server forwarding traffic to an authenticated SOCKS5 upstream."""

    def __init__(
        self,
        socks_host: str,
        socks_port: int,
        username: str = "",
        password: str = "",
        bind_host: str = "127.0.0.1",
    ):
        self.socks_host = socks_host
        self.socks_port = socks_port
        self.username = username
        self.password = password
        self.bind_host = bind_host
        self._server = None
        self._thread = None
        self.port = 0

    def start(self):
        if self._server is not None:
            return
        self._server = _ThreadedHTTPServer((self.bind_host, 0), _ProxyRequestHandler)
        self._server.bridge = self
        self.port = self._server.server_address[1]
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)
        self._thread.start()

    def stop(self):
        if self._server is not None:
            try:
                self._server.shutdown()
                self._server.server_close()
            except OSError:
                pass
            self._server = None
        if self._thread is not None:
            self._thread.join(timeout=2.0)
            self._thread = None
        self.port = 0
