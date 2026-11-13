<?php
declare (strict_types=1);

namespace app\model;

use think\Model;
use app\service\ShareOriginPolicy;
use InvalidArgumentException;

/**
 * @mixin Model
 */
class SharePage extends Model
{
    protected $table = 'share';
    protected $pk = 'id';

    public function addSharePage($data): array
    {
        try {
            $data['api_allowed_origins'] = ShareOriginPolicy::normalizeWhitelist($data['api_allowed_origins'] ?? '');
        } catch (InvalidArgumentException $e) {
            return ['status' => false, 'msg' => $e->getMessage()];
        }
        if (!$this->accountsBelongToOwner($data)) {
            return ['status' => false, 'msg' => '所选账号不存在或不属于分享页所有者'];
        }
        if ($this->fetchByLink($data['share_link'])) {
            return ['status' => false, 'msg' => '分享链接已存在'];
        }
        $share = new SharePage();
        $share->create($data);
        return ['status' => true, 'msg' => '添加成功'];
    }

    public function fetchByLink($link): ?SharePage
    {
        $share = $this->where('share_link', $link)->find();
        if (!$share) return null;
        $share->account_list = array_map('intval', explode(",", $share->account_list));
        return $share;
    }

    public function updateSharePage($id, $data): array
    {
        try {
            $data['api_allowed_origins'] = ShareOriginPolicy::normalizeWhitelist($data['api_allowed_origins'] ?? '');
        } catch (InvalidArgumentException $e) {
            return ['status' => false, 'msg' => $e->getMessage()];
        }
        if (!$this->accountsBelongToOwner($data)) {
            return ['status' => false, 'msg' => '所选账号不存在或不属于分享页所有者'];
        }
        if (!$this) {
            $share = $this->fetch($id);
        } else {
            $share = $this;
        }
        if (!$share) {
            return ['status' => false, 'msg' => '分享链接不存在'];
        }
        // 检查链接重复
        if ($share->share_link != $data['share_link']) {
            if ($this->fetchByLink($data['share_link'])) {
                return ['status' => false, 'msg' => '分享链接已存在'];
            }
        }
        $share->update($data, ['id' => $id]);
        return ['status' => true, 'msg' => '修改成功'];
    }

    public function fetch($id): ?SharePage
    {
        $share = $this->where('id', $id)->find();
        if (!$share) return null;
        $share->account_list = array_map('intval', explode(",", $share->account_list));
        return $share;
    }

    public function deleteSharePage($id): array
    {
        if (!$this) {
            $share = $this->fetch($id);
        } else {
            $share = $this;
        }
        if (!$share) {
            return ['status' => false, 'msg' => '分享链接不存在'];
        }
        $result = $share->delete();
        return ['status' => $result, 'msg' => $result ? '删除成功' : '删除失败'];
    }

    private function accountsBelongToOwner(array $data): bool
    {
        $list = $data['account_list'] ?? '';
        if (!is_string($list) || strlen($list) > 512 || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $list)) {
            return false;
        }
        $ids = array_unique(explode(',', $list));
        return (new Account())->whereIn('id', $ids)->where('owner', $data['owner'] ?? 0)->count() === count($ids);
    }

    public function getAccessError(): string
    {
        if (!$this->enable) {
            return '此分享页已关闭';
        }
        if ($this->expire !== null && $this->expire !== '') {
            $expire = strtotime($this->expire);
            if ($expire === false || $expire <= time()) {
                return '此分享链接已过期';
            }
        }
        return '';
    }
}
