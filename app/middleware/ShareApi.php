<?php
declare (strict_types=1);

namespace app\middleware;

use app\model\SharePage;
use app\service\ShareOriginPolicy;
use Closure;

class ShareApi
{
    public function handle($request, Closure $next)
    {
        $headers = ['Cache-Control' => 'no-store', 'Vary' => 'Origin'];
        $share = (new SharePage())->fetchByLink($request->route('link', ''));
        if (!$share) {
            return json(['code' => 404, 'status' => false, 'msg' => '分享页不存在'], 404, $headers);
        }
        $origin = $request->header('origin', '');
        if (!ShareOriginPolicy::allows($share->api_allowed_origins, $origin)) {
            return json(['code' => 403, 'status' => false, 'msg' => '请求来源不在API域名白名单中'], 403, $headers);
        }
        $headers['Access-Control-Allow-Origin'] = ShareOriginPolicy::normalizeOrigin($origin);
        $error = $share->getAccessError();
        if ($error !== '') {
            return json(['code' => 403, 'status' => false, 'msg' => $error], 403, $headers);
        }
        if ($request->isOptions()) {
            $method = strtoupper($request->header('access-control-request-method', ''));
            $requestedHeaders = array_filter(array_map('trim', explode(',', strtolower($request->header('access-control-request-headers', '')))));
            if (!in_array($method, ['GET', 'POST'], true) || array_diff($requestedHeaders, ['content-type'])) {
                return json(['code' => 403, 'status' => false, 'msg' => '不支持的跨域请求'], 403, $headers);
            }
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
            $headers['Access-Control-Allow-Headers'] = 'Content-Type';
            $headers['Vary'] = 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers';
            return json(null, 204, $headers);
        }
        if (!$request->isGet() && !$request->isPost()) {
            $headers['Allow'] = 'GET, POST, OPTIONS';
            return json(['code' => 405, 'status' => false, 'msg' => '请求方式错误'], 405, $headers);
        }
        $password = $request->route('password', '');
        if ($share->password !== null && $share->password !== ''
            && (!is_string($password) || !hash_equals((string)$share->password, $password))) {
            return json(['code' => 403, 'status' => false, 'msg' => '分享密码错误'], 403, $headers);
        }
        $request->share = $share;
        return $next($request)->header($headers);
    }
}
