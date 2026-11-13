<?php
declare (strict_types=1);

namespace app\middleware;

use app\model\SharePage;
use Closure;

class Share
{
    public function handle($request, Closure $next)
    {
        $shareLink = $request->route('link');
        if (!$shareLink) {
            return view('share/error', ['errorTitle' => '页面不存在', 'errorMsg' => '此分享链接不存在']);
        }
        $share = new SharePage();
        $share = $share->fetchByLink($shareLink);
        if (!$share) {
            return view('share/error', ['errorTitle' => '页面不存在', 'errorMsg' => '此分享链接不存在']);
        }
        $error = $share->getAccessError();
        if ($error !== '') {
            return view('share/error', ['errorTitle' => '无法访问', 'errorMsg' => $error])->code(403)->header(['Cache-Control' => 'no-store']);
        }
        if ($share->password !== null && $share->password !== '') {
            if ($request->param('password') === null || $request->param('password') === '') {
                return view('share/password', ['link' => $shareLink]);
            }
            if (!is_string($request->param('password')) || !hash_equals((string)$share->password, $request->param('password'))) {
                return view('share/error', ['errorTitle' => '密码错误', 'errorMsg' => '分享链接密码错误']);
            }
        }
        if ($request->route('id') !== null) {
            $id = (int)$request->route('id');
            $account = (new \app\model\Account())->fetch($id);
            if (!in_array($id, $share->account_list, true) || !$account || !$account->enable || $account->owner != $share->owner) {
                return view('share/error', ['errorTitle' => '无权操作', 'errorMsg' => '账号不属于此分享页或已停用'])->code(403);
            }
            $request->id = $id;
            $request->link = $shareLink;
        }
        $request->share = $share;
        return $next($request);
    }
}
