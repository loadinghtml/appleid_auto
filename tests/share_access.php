<?php
declare (strict_types=1);

// Run: php tests/share_access.php (requires Composer dependencies and pdo_sqlite).
// Only an in-memory database is used; no application database is accessed.
require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\ApiController;
use app\controller\ShareController;
use app\controller\UserController;
use app\controller\AdminController;
use app\middleware\Share;
use app\middleware\ShareApi;
use app\model\SharePage;
use app\service\ShareOriginPolicy;
use think\App;
use think\facade\Db;
use think\facade\Session;
use think\Request;

$app = new App(dirname(__DIR__) . DIRECTORY_SEPARATOR);
$app->initialize();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
$app->config->set(['auto_timestamp' => false], 'database');
$app->config->set([
    'auto_timestamp' => false,
    'default' => 'test',
    'connections' => ['test' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
], 'database');
Db::execute('CREATE TABLE share (id INTEGER PRIMARY KEY AUTOINCREMENT, share_link TEXT, account_list TEXT, owner INTEGER, password TEXT, html TEXT, remark TEXT, expire TEXT, enable INTEGER DEFAULT 1, api_allowed_origins TEXT)');
Db::execute('CREATE TABLE account (id INTEGER PRIMARY KEY, owner INTEGER, enable INTEGER, username TEXT, password TEXT, frontend_remark TEXT, message TEXT, last_check TEXT, check_interval INTEGER, min_manual_unlock INTEGER)');
Db::execute('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT, is_admin INTEGER)');
foreach ([[1, 10, 1], [2, 20, 1], [3, 10, 0]] as [$id, $owner, $enabled]) {
    Db::name('account')->insert(['id' => $id, 'owner' => $owner, 'enable' => $enabled, 'username' => 'user' . $id, 'password' => 'secret' . $id, 'frontend_remark' => '', 'message' => '正常', 'last_check' => date('Y-m-d H:i:s'), 'check_interval' => 30, 'min_manual_unlock' => 1]);
}
Db::name('share')->insert(['id' => 1, 'share_link' => 'demo', 'account_list' => '1,2,3', 'owner' => 10, 'password' => null, 'html' => '', 'remark' => '', 'expire' => null, 'enable' => 1, 'api_allowed_origins' => "https://allowed.example\nhttps://app.example:8443"]);

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shareRequest(string $origin = '', string $method = 'GET', array $route = [], array $headers = [], array $post = []): Request
{
    global $app;
    $request = (new Request())->withRoute(array_merge(['link' => 'demo'], $route))
        ->withHeader(array_merge(['origin' => $origin], $headers))->withPost($post)->withServer(['REQUEST_METHOD' => $method]);
    $request->setMethod($method);
    $app->instance('request', $request);
    return $request;
}

function apiRequest(Request $request, ?bool &$called = null)
{
    global $app;
    $called = false;
    return (new ShareApi())->handle($request, function () use ($app, &$called) {
        $called = true;
        return (new ApiController($app))->getSharepage('demo');
    });
}

check(ShareOriginPolicy::normalizeWhitelist("HTTPS://Allowed.Example:443/\nhttps://allowed.example") === 'https://allowed.example', 'Origins must normalize and deduplicate');
foreach (['', 'null', 'https://evil.example', 'https://allowed.example.evil.test', 'https://sub.allowed.example', 'http://allowed.example', 'https://allowed.example:8443', 'https://allowed.example@evil.example', "https://allowed.example\r\n", 'https://allowed.example/path', 'https://allowed.example?x=1'] as $origin) {
    $response = apiRequest(shareRequest($origin), $called);
    check($response->getCode() === 403 && !$called, 'Disallowed origin reached controller: ' . json_encode($origin));
    check(!isset($response->getHeader()['Access-Control-Allow-Origin']), 'Disallowed origin received CORS permission');
    check(strpos($response->getContent(), 'secret') === false, 'Denied response exposed account secrets');
}
foreach (['https://allowed.example', 'https://app.example:8443'] as $origin) {
    $response = apiRequest(shareRequest($origin), $called);
    $data = json_decode($response->getContent(), true);
    check($response->getCode() === 200 && $called, 'Allowed origin was denied');
    check(count($data['accounts']) === 1 && $data['accounts'][0]['id'] === 1, 'Foreign or disabled account leaked');
    check($response->getHeader()['Access-Control-Allow-Origin'] === $origin, 'CORS must echo exactly the allowed origin');
    check($response->getHeader()['Cache-Control'] === 'no-store', 'Account response must not be cached');
}

$response = apiRequest(shareRequest('https://allowed.example', 'OPTIONS', [], ['access-control-request-method' => 'GET']), $called);
check($response->getCode() === 204 && !$called, 'Preflight must succeed without invoking account controller');
check($response->getHeader()['Access-Control-Allow-Methods'] === 'GET, POST, OPTIONS', 'Missing preflight methods');
$response = apiRequest(shareRequest('https://allowed.example', 'OPTIONS', [], ['access-control-request-method' => 'POST', 'access-control-request-headers' => 'Content-Type']), $called);
check($response->getCode() === 204, 'JSON preflight should succeed');
foreach ([['access-control-request-method' => 'DELETE'], ['access-control-request-method' => 'POST', 'access-control-request-headers' => 'X-Unknown']] as $headers) {
    $response = apiRequest(shareRequest('https://allowed.example', 'OPTIONS', [], $headers), $called);
    check($response->getCode() === 403 && !$called, 'Unsupported preflight was allowed');
}
$response = apiRequest(shareRequest('https://allowed.example', 'DELETE'), $called);
check($response->getCode() === 405 && !$called, 'Unsupported API method was allowed');

Db::name('share')->where('id', 1)->update(['api_allowed_origins' => '']);
check(apiRequest(shareRequest('https://allowed.example'))->getCode() === 403, 'Empty whitelist must deny');
Db::name('share')->where('id', 1)->update(['api_allowed_origins' => 'https://allowed.example', 'enable' => 0]);
check(apiRequest(shareRequest('https://allowed.example'))->getCode() === 403, 'Disabled share API was accessible');
$pageCalled = false;
$next = function ($request) use (&$pageCalled, $app) {
    $pageCalled = true;
    return (new ShareController($app))->index($request);
};
$response = (new Share())->handle(shareRequest(), $next);
check($response->getCode() === 403 && !$pageCalled, 'Disabled share page was accessible');
$response = (new Share())->handle(shareRequest('', 'GET', ['id' => '1']), $next);
check($response->getCode() === 403 && !$pageCalled, 'Unlock bypassed disabled state');
Db::name('share')->where('id', 1)->update(['enable' => 1, 'expire' => '2000-01-01 00:00:00']);
check(apiRequest(shareRequest('https://allowed.example'))->getCode() === 403, 'Expired API was accessible');
check((new Share())->handle(shareRequest(), $next)->getCode() === 403, 'Expired page was accessible');

Db::name('share')->where('id', 1)->update(['expire' => null, 'password' => '0']);
check(apiRequest(shareRequest('https://allowed.example'))->getCode() === 403, 'Missing API password was accepted');
check(apiRequest(shareRequest('https://allowed.example', 'GET', ['password' => 'wrong']))->getCode() === 403, 'Incorrect API password was accepted');
check(apiRequest(shareRequest('https://allowed.example', 'GET', ['password' => '0']))->getCode() === 200, 'Zero-string password was not respected');
check(apiRequest(shareRequest('https://allowed.example', 'OPTIONS', [], ['access-control-request-method' => 'GET']))->getCode() === 204, 'Preflight must not require the share password');
$request = shareRequest('', 'POST', [], [], ['password' => '0']);
$response = (new Share())->handle($request, $next);
check($pageCalled && strpos($response->getContent(), 'secret1') !== false && strpos($response->getContent(), 'secret2') === false, 'Page render exposed foreign account or rejected valid password');

Db::name('share')->where('id', 1)->update(['password' => null]);
foreach ([['id' => '2'], ['id' => '3'], ['id' => '999'], ['link' => 'missing', 'id' => '1']] as $route) {
    $pageCalled = false;
    (new Share())->handle(shareRequest('', 'GET', $route), $next);
    check(!$pageCalled, 'Invalid unlock target reached controller');
}
$pageCalled = false;
(new Share())->handle(shareRequest('', 'GET', ['id' => '1']), function ($request) use (&$pageCalled) {
    $pageCalled = $request->id === 1 && $request->link === 'demo';
    return 'ok';
});
check($pageCalled, 'Valid unlock target was rejected');

$data = ['share_link' => 'newpage', 'account_list' => '1', 'owner' => 10, 'password' => null, 'html' => '', 'remark' => '', 'expire' => null, 'enable' => 1, 'api_allowed_origins' => 'https://allowed.example'];
$result = (new SharePage())->addSharePage($data);
check($result['status'], 'Valid share creation failed');
$model = (new SharePage())->fetchByLink('newpage');
$data['enable'] = 0;
check($model->updateSharePage($model->id, $data)['status'], 'Disabling share failed');
check((new SharePage())->fetchByLink('newpage')->getAccessError() !== '', 'Disabled state was not persisted');
$data['enable'] = 1;
check($model->updateSharePage($model->id, $data)['status'], 'Re-enabling share failed');
check((new SharePage())->fetchByLink('newpage')->getAccessError() === '', 'Re-enabled state was not persisted');
$data['account_list'] = '2';
check(!$model->updateSharePage($model->id, $data)['status'], 'Foreign account assignment was accepted');
$data['account_list'] = '1';
foreach (['*', 'https://*.example.com', 'https://allowed.example/path', 'https://user:pass@allowed.example', 'javascript:alert(1)'] as $invalid) {
    $data['api_allowed_origins'] = $invalid;
    check(!$model->updateSharePage($model->id, $data)['status'], 'Invalid whitelist was saved: ' . $invalid);
}

// Render the changed edit forms with the actual template engine.
$user = new \app\model\User();
$user->id = 10;
$user->username = 'tester';
$user->is_admin = 0;
foreach (['user', 'admin'] as $role) {
    $html = view('/' . $role . '/shareDetail', ['share' => $model, 'accounts' => [1 => 'user1'], 'action' => 'edit', 'user' => $user])->getContent();
    check(strpos($html, 'name="enable"') !== false && strpos($html, 'api_allowed_origins') !== false, 'Share edit form failed to render');
}

// Both management forms must persist the switch and whitelist correctly.
Session::set('user_id', 10);
foreach ([UserController::class, AdminController::class] as $controllerClass) {
    foreach ([false, true] as $enabled) {
        $post = ['action' => 'edit', 'account_list' => ['1'], 'share_link' => 'newpage', 'owner' => '10',
            'password' => '', 'html' => '', 'remark' => '', 'expire' => '',
            'api_allowed_origins' => "HTTPS://Allowed.Example:443/\nhttps://allowed.example"];
        if ($enabled) {
            $post['enable'] = '1';
        }
        shareRequest('', 'POST', [], [], $post);
        ob_start();
        (new $controllerClass($app))->shareUpdate($model->id);
        ob_end_clean();
        $saved = (new SharePage())->fetchByLink('newpage');
        check((bool)$saved->enable === $enabled, 'Management form did not persist enable state');
        check($saved->api_allowed_origins === 'https://allowed.example', 'Management form did not persist normalized whitelist');
    }
}

$post['account_list'] = ['2'];
shareRequest('', 'POST', [], [], $post);
ob_start();
(new UserController($app))->shareUpdate($model->id);
ob_end_clean();
check((new SharePage())->fetchByLink('newpage')->account_list === [1], 'User form allowed foreign account assignment');
Session::set('user_id', 20);
$post['enable'] = null;
$post['account_list'] = ['1'];
shareRequest('', 'POST', [], [], $post);
ob_start();
(new UserController($app))->shareUpdate($model->id);
ob_end_clean();
check((bool)(new SharePage())->fetchByLink('newpage')->enable, 'Other user disabled a share they do not own');
Session::delete('user_id');

// Exercise the registered HTTP route and its middleware, not just direct calls.
$request = shareRequest('https://allowed.example')->withRoute([])->withServer([
    'REQUEST_METHOD' => 'GET', 'PATH_INFO' => '/shareapi/demo',
    'REQUEST_URI' => '/shareapi/demo', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'localhost',
]);
$response = $app->http->run($request);
check($response->getCode() === 200 && count(json_decode($response->getContent(), true)['accounts'] ?? []) === 1, 'Registered API route failed: ' . $response->getContent());
check(($response->getHeader()['Access-Control-Allow-Origin'] ?? '') === 'https://allowed.example', 'Registered API route bypassed middleware');
foreach ([
    ['GET', '/shareapi/demo', '', [], 403],
    ['GET', '/shareapi/demo', 'https://evil.example', [], 403],
    ['OPTIONS', '/shareapi/demo', 'https://allowed.example', ['access-control-request-method' => 'GET'], 204],
    ['OPTIONS', '/shareapi/demo', '', ['access-control-request-method' => 'GET'], 403],
] as [$method, $path, $origin, $headers, $status]) {
    $request = shareRequest($origin, $method, [], $headers)->withRoute([])->withServer([
        'REQUEST_METHOD' => $method, 'PATH_INFO' => $path, 'REQUEST_URI' => $path,
        'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'localhost',
    ]);
    $response = $app->http->run($request);
    check($response->getCode() === $status, 'Registered route gave wrong status: ' . $method . ' ' . $path . ' ' . $response->getContent());
}
Db::name('share')->where('id', 1)->update(['enable' => 0]);
foreach (['/share/demo', '/share/demo/1/unlock', '/shareapi/demo'] as $path) {
    $request = shareRequest('https://allowed.example')->withRoute([])->withServer([
        'REQUEST_METHOD' => 'GET', 'PATH_INFO' => $path, 'REQUEST_URI' => $path,
        'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'localhost',
    ]);
    $response = $app->http->run($request);
    check($response->getCode() === 403, 'Disabled share accessible through registered route: ' . $path);
    check(strpos($response->getContent(), 'secret') === false, 'Disabled route exposed secrets');
}
foreach (['user', 'admin'] as $role) {
    $html = view('/' . $role . '/share', ['shares' => $app->shareService->fetchAll(), 'shareURL' => 'https://share.example/share/'])->getContent();
    check(strpos($html, '已开启') !== false && strpos($html, '已关闭') !== false, 'Share list did not render both states');
}

echo "PASS: $checks share access checks\n";
