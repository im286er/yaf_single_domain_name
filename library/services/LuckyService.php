<?php
/**
 * 抽奖业务封装。
 * @author winerQin
 * @date 2016-10-19
 */

namespace services;

use common\YCore;
use winer\Validator;
use models\DbBase;
use models\GmLuckyGoods;
use models\User;
use models\GmLuckyPrize;
use common\YUrl;
use models\Admin;
use models\MallUserAddress;
use models\District;
class LuckyService extends BaseService {

    const GOODS_TYPE_JB = 'jb'; // 金币。
    const GOODS_TYPE_QB = 'qb'; // Q币。
    const GOODS_TYPE_HF = 'hf'; // 话费。
    const GOODS_TYPE_SW = 'sw'; // 实物。
    const GOODS_TYPE_NO = 'no'; // 未中奖。

    /**
     * 商品类型。
     * @var array
     */
    public static $goods_type_dict = [
        'jb' => '金币',
        'qb' => 'Q币',
        'hf' => '话费',
        'sw' => '实物',
        'no' => '未中奖'
    ];

    /**
     * 设置抽奖奖品。
     * -- Example start --
     * $goods = [
     *      [
     *          'goods_name' => '奖品名称',
     *          'day_max'    => '每天中奖最大次数。0代表不限制',
     *          'min_range'  => '随机最小概率值',
     *          'max_range'  => '随机最大概率值',
     *          'goods_type' => '商品类型',
     *          'image_url'  => '奖品图片'
     *      ],
     *      ......
     * ];
     * -- Example end --
     * @param number $admin_id 管理员ID。
     * @param array $goods 奖品列表。奖品格子只有九个。也就是说奖品也只能设置九个。
     * @return boolean
     */
    public static function setLuckyGoods($admin_id, $goods) {
        if (count($goods) !== 9) {
            YCore::exception(-1, '奖品必须9个');
        }
        $db = new DbBase();
        $db->beginTransaction();
        $db->rawExec('TRUNCATE TABLE gm_lucky_goods');
        foreach ($goods as $item) {
            if (!Validator::is_len($item['goods_name'], 1, 50, true)) {
                $db->rollBack();
                YCore::exception(-1, '奖品名称长度不能大于50个字符');
            }
            if (!Validator::is_number_between($item['day_max'], 0, 1000000)) {
                $db->rollBack();
                YCore::exception(-1, '奖品每天的中奖最大次数不能超过1000000次');
            }
            if (!Validator::is_number_between($item['min_range'], 1, 1000000)) {
                $db->rollBack();
                YCore::exception(-1, '随机最小概率值不能超过100000');
            }
            if (!Validator::is_number_between($item['max_range'], 1, 1000000)) {
                $db->rollBack();
                YCore::exception(-1, '随机最大概率值不能超过100000');
            }
            if (!array_key_exists($item['goods_type'], self::$goods_type_dict)) {
                $db->rollBack();
                YCore::exception(-1, '商品类型不正确');
            }
            if (strlen($item['image_url']) === 0) {
                $db->rollBack();
                YCore::exception(-1, '奖品图片必须设置');
            }
            if (!Validator::is_len($item['image_url'], 1, 100, true)) {
                $db->rollBack();
                YCore::exception(-1, '图片长度不能超过100个字符');
            }
            $item['created_by']   = $admin_id;
            $item['created_time'] = $_SERVER['REQUEST_TIME'];
            $lucky_goods_model = new GmLuckyGoods();
            $ok = $lucky_goods_model->insert($item);
            if (!$ok) {
                $db->rollBack();
                YCore::exception(-1, '设置失败');
            }
        }
        $db->commit();
        return true;
    }

    /**
     * 获取中将奖品[管理后台]。
     * @param int $is_has_no 是否包含未中奖的奖品项。
     * @return array
     */
    public static function getAdminLuckyGoodsList($is_has_no = true) {
        $lucky_goods_model = new GmLuckyGoods();
        $columns = [
            'id', 'day_max', 'min_range', 'max_range',
            'goods_name', 'image_url', 'goods_type',
            'created_by', 'created_time'
        ];
        $lucky_goods_list = $lucky_goods_model->fetchAll($columns, [], 0, 'id ASC');
        foreach ($lucky_goods_list as $k => $v) {
            $admin_model = new Admin();
            $admin_info  = $admin_model->fetchOne([], ['admin_id' => $v['created_by']]);
            $v['created_by']   = "{$admin_info['realname']}({$admin_info['username']})";
            $v['created_time'] = YCore::format_timestamp($v['created_time']);
            $lucky_goods_list[$k] = $v;
        }
        return $lucky_goods_list;
    }

    /**
     * 获取中将奖品[前台用户]。
     * @param int $is_has_no 是否包含未中奖的奖品项。
     * @return array
     */
    public static function getUserLuckyGoodsList($is_has_no = true) {
        $lucky_goods_model = new GmLuckyGoods();
        $columns = [
            'goods_name', 'image_url', 'goods_type',
        ];
        $lucky_goods_list = $lucky_goods_model->fetchAll($columns, [], 0, 'id ASC');
        foreach ($lucky_goods_list as $k => $v) {
            $admin_model = new Admin();
            $v['goods_type_label'] = self::$goods_type_dict[$v['goods_type']];
            $v['image_url']        = YUrl::filePath($v['image_url']);
            $lucky_goods_list[$k] = $v;
        }
        return $lucky_goods_list;
    }

    /**
     * 用户发起抽奖。
     * @param number $user_id 用户ID。
     * @return array
     */
    public static function startDoLucky($user_id) {
        $lucky_goods_model = new GmLuckyGoods();
        $lucky_goods_list  = $lucky_goods_model->fetchAll();
        $rand_value = mt_rand(1, 10000);
        $prize_info = []; // 保存抽中的奖品信息。
        foreach ($lucky_goods_list as $item) {
            if ($rand_value >= $item['min_range'] && $rand_value <= $item['max_range']) {
                $prize_info = $item;
            }
        }
        if ($prize_info['goods_type'] == self::GOODS_TYPE_NO) {
            self::writeLuckyPrizeRecord($user_id, '未中奖', self::GOODS_TYPE_NO, $rand_value);
            return [
                'goods_name' => '未中奖',
                'goods_type' => self::GOODS_TYPE_NO
            ];
        }
        $lucky_goods_time_key = "lucky_goods_time_{$prize_info['id']}";
        $cache_key = "lucky_goods_{$prize_info['id']}";
        $cache_db  = YCore::getCache();
        $cache_val = $cache_db->get($cache_key);
        if ($cache_val === false) {
            $cache_db->set($lucky_goods_time_key, $_SERVER['REQUEST_TIME']);
            $cache_db->set($cache_key, 1);
            self::writeLuckyPrizeRecord($user_id, $prize_info['goods_name'], $prize_info['goods_type'], $rand_value);
            return [
                'goods_name' => $prize_info['goods_name'],
                'goods_type' => $prize_info['goods_type']
            ];
        } else {
            $last_end_time = strtotime(date('Y-m-d 00:00:00', $_SERVER['REQUEST_TIME']));
            $lucky_goods_time = $cache_db->get($lucky_goods_time);
            if ($lucky_goods_time > $last_end_time) { // 当天。
                if ($cache_val >= $prize_info['day_max']) { // 超过了奖品当天允许抽中的数量。
                    self::writeLuckyPrizeRecord($user_id, '未中奖', self::GOODS_TYPE_NO, $rand_value);
                    return [
                        'goods_name' => '未中奖',
                        'goods_type' => self::GOODS_TYPE_NO
                    ];
                } else {
                    $cache_db->set($cache_key, $cache_val+1);
                    $cache_db->set($lucky_goods_time_key, $_SERVER['REQUEST_TIME']);
                    self::writeLuckyPrizeRecord($user_id, $prize_info['goods_name'], $prize_info['goods_type'], $rand_value);
                    return [
                        'goods_name' => $prize_info['goods_name'],
                        'goods_type' => $prize_info['goods_type']
                    ];
                }
            } else { // 昨天。
                $cache_db->set($cache_key, 1);
                $cache_db->set($lucky_goods_time_key, $_SERVER['REQUEST_TIME']);
                self::writeLuckyPrizeRecord($user_id, $prize_info['goods_name'], $prize_info['goods_type'], $rand_value);
                return [
                    'goods_name' => $prize_info['goods_name'],
                    'goods_type' => $prize_info['goods_type']
                ];
            }
        }
    }

    /**
     * 获取抽奖记录详情。
     * @param number $id 抽奖记录ID。
     * @return array
     */
    public static function getAdminLuckyPrizeDetail($id) {
        $lucky_prize_model = new GmLuckyPrize();
        $columns = [
            'id', 'goods_name', 'goods_type', 'is_send', 'send_time', 'range_val', 'get_info'
        ];
        $result = $lucky_prize_model->fetchOne($columns, ['id' => $id, 'status' => 1]);
        if (empty($result)) {
            YCore::exception(-1, '抽奖记录不存在');
        }
        return $result;
    }

    /**
     * 写入用户抽奖记录。
     * @param number $user_id 用户ID。
     * @param string $goods_name 奖品名称。
     * @param string $goods_type 奖品类型。
     * @param number $range_val 随机值。
     * @return boolean
     */
    public static function writeLuckyPrizeRecord($user_id, $goods_name, $goods_type, $range_val) {
        $lucky_prize_model = new GmLuckyPrize();
        $data = [
            'user_id'      => $user_id,
            'goods_name'   => $goods_name,
            'goods_type'   => $goods_type,
            'range_val'    => $range_val,
            'status'       => 1,
            'created_time' => $_SERVER['REQUEST_TIME']
        ];
        $ok = $lucky_prize_model->insert($data);
        if (!$ok) {
            YCore::exception(-1, '服务器繁忙');
        }
        return true;
    }

    /**
     * 管理员获取用户中奖记录。
     * @param string $username 用户名。
     * @param string $mobilephone 手机号码。
     * @param string $goods_name 奖品名称。
     * @param string $goods_type 奖品类型。
     * @param number $page 当前页码。
     * @param number $count 每页显示条数。
     * @return array
     */
    public static function getAdminLuckyPrizeList($username = '', $mobilephone = '', $goods_name = '', $goods_type = '', $page = 1, $count = 20) {
        $offset  = self::getPaginationOffset($page, $count);
        $columns = ' * ';
        $where   = ' WHERE status = :status ';
        $params  = [
            ':status' => 1
        ];
        $user_model = new User();
        if (strlen($username) !== 0) {
            $userinfo = $user_model->fetchOne([], ['username' => $username]);
            $where .= ' AND user_id = :user_id ';
            $params[':user_id'] = $userinfo ? $userinfo['user_id'] : 0;
        } else if (strlen($mobilephone) !== 0) {
            $userinfo = $user_model->fetchOne([], ['mobilephone' => $mobilephone]);
            $where .= ' AND user_id = :user_id ';
            $params[':user_id'] = $userinfo ? $userinfo['user_id'] : 0;
        }
        if (strlen($goods_name) !== 0) {
            $where .= ' AND goods_name LIKE :goods_name ';
            $params[':goods_name'] = "%{$goods_name}%";
        }
        if (strlen($goods_type) !== 0) {
            $where .= ' AND goods_type = :goods_type ';
            $params[':goods_type'] = $goods_type;
        }
        $order_by = ' ORDER BY id DESC ';
        $sql = "SELECT COUNT(1) AS count FROM gm_lucky_prize {$where}";
        $default_db = new DbBase();
        $count_data = $default_db->rawQuery($sql, $params)->rawFetchOne();
        $total = $count_data ? $count_data['count'] : 0;
        $sql   = "SELECT {$columns} FROM gm_lucky_prize {$where} {$order_by} LIMIT {$offset},{$count}";
        $list  = $default_db->rawQuery($sql, $params)->rawFetchAll();
        $userinfos = [];
        foreach ($list as $k => $v) {
            if (isset($userinfos[$v['user_id']])) {
                $v['username']    = $userinfos[$v['user_id']]['username'];
                $v['mobilephone'] = $userinfos[$v['user_id']]['mobilephone'];
            } else {
                $userinfo = $user_model->fetchOne([], ['user_id' => $v['user_id']]);
                $v['username']    = $userinfo ? $userinfo['username'] : '';
                $v['mobilephone'] = $userinfo ? $userinfo['mobilephone'] : '';
                $userinfos[$v['user_id']] = $userinfo;
            }
            // 判断是否允许发奖。决定发奖按钮是否显示。大于24小时则不能修改发奖凭证等信息了。
            $v['is_allow_send'] = true;
            if ($v['send_time'] > 0 && (($_SERVER['REQUEST_TIME'] - $v['send_time']) > 86400)) {
                $v['is_allow_send'] = false;
            }
            $v['goods_type']   = self::$goods_type_dict[$v['goods_type']];
            $v['is_send']      = $v['is_send'] ? '是' : '否';
            $v['send_time']    = YCore::format_timestamp($v['send_time']);
            $v['created_time'] = YCore::format_timestamp($v['created_time']);
            $list[$k] = $v;
        }
        $result = [
            'list'   => $list,
            'total'  => $total,
            'page'   => $page,
            'count'  => $count,
            'isnext' => self::IsHasNextPage($total, $page, $count)
        ];
        return $result;
    }

    /**
     * 获取用户中奖记录。
     * @param number $user_id 用户ID。
     * @param string $goods_name 奖品名称。
     * @param string $goods_type 奖品类型。
     * @param number $page 当前页码。
     * @param number $count 每页显示条数。
     * @return array
     */
    public static function getUserLuckyPrizeList($user_id, $goods_name = '', $goods_type = '', $page = 1, $count = 20) {
        $offset  = self::getPaginationOffset($page, $count);
        $columns = ' * ';
        $where   = ' WHERE user_id = :user_id AND status = :status';
        $params  = [
            ':status'  => 1,
            ':user_id' => $user_id
        ];
        if (strlen($goods_name) !== 0) {
            $where .= ' AND goods_name LIKE :goods_name ';
            $params[':goods_name'] = "%{$goods_name}%";
        }
        if (strlen($goods_type) !== 0) {
            $where .= ' AND goods_type = :goods_type ';
            $params[':goods_type'] = $goods_type;
        }
        $order_by = ' ORDER BY id DESC ';
        $sql = "SELECT COUNT(1) AS count FROM gm_lucky_prize {$where}";
        $default_db = new DbBase();
        $count_data = $default_db->rawQuery($sql, $params)->rawFetchOne();
        $total = $count_data ? $count_data['count'] : 0;
        $sql   = "SELECT {$columns} FROM gm_lucky_prize {$where} {$order_by} LIMIT {$offset},{$count}";
        $list  = $default_db->rawQuery($sql, $params)->rawFetchAll();
        foreach ($list as $k => $v) {
            $v['created_time'] = YCore::format_timestamp($v['created_time']);
            $list[$k] = $v;
        }
        $result = [
            'list'   => $list,
            'total'  => $total,
            'page'   => $page,
            'count'  => $count,
            'isnext' => self::IsHasNextPage($total, $page, $count)
        ];
        return $result;
    }

    /**
     * 获取最新中奖记录。
     * @param number $count 要取的记录条数。
     * @return array
     */
    public static function getNewestLuckyPrizeList($count = 20) {
        $page    = 1;
        $offset  = self::getPaginationOffset($page, $count);
        $columns = ' * ';
        $where   = ' WHERE status = :status AND goods_type != :goods_type ';
        $params  = [
            ':status'     => 1,
            ':goods_type' => self::GOODS_TYPE_NO
        ];
        $order_by = ' ORDER BY id DESC ';
        $sql = "SELECT COUNT(1) AS count FROM gm_lucky_prize {$where}";
        $default_db = new DbBase();
        $count_data = $default_db->rawQuery($sql, $params)->rawFetchOne();
        $total = $count_data ? $count_data['count'] : 0;
        $sql   = "SELECT {$columns} FROM gm_lucky_prize {$where} {$order_by} LIMIT {$offset},{$count}";
        $list  = $default_db->rawQuery($sql, $params)->rawFetchAll();
        $userinfos = [];
        $user_model = new User();
        foreach ($list as $k => $v) {
            if (isset($userinfos[$v['user_id']])) {
                $v['username'] = YCore::asterisk($userinfos[$v['user_id']]['username']);
            } else {
                $userinfo = $user_model->fetchOne([], ['user_id' => $v['user_id']]);
                $v['username'] = $userinfo ? YCore::asterisk($userinfo['username']) : '';
                $userinfos[$v['user_id']] = $userinfo;
            }
            $v['created_time'] = YCore::format_timestamp($v['created_time']);
            $list[$k] = $v;
        }
        $result = [
            'list'   => $list,
            'total'  => $total,
            'page'   => $page,
            'count'  => $count,
            'isnext' => self::IsHasNextPage($total, $page, $count)
        ];
        return $result;
    }

    /**
     * 删除中奖记录。
     * @param number $admin_id 管理员ID。
     * @param number $id 中奖记录ID。
     * @return boolean
     */
    public static function deletePrizeRecord($admin_id, $id) {
        $lucky_prize_model = new GmLuckyPrize();
        $result = $lucky_prize_model->fetchOne([], ['id' => $id, 'status' => 1]);
        if (empty($result)) {
            YCore::exception(-1, '中奖记录不存在');
        }
        if ($result['is_send'] == 1) {
            YCore::exception(-1, '已发奖的记录不能删除');
        }
        $data = [
            'status'        => 2,
            'modified_by'   => $admin_id,
            'modified_time' => $_SERVER['REQUEST_TIME']
        ];
        $ok = $lucky_prize_model->update($data, ['id' => $id, 'status' => 1]);
        if (!$ok) {
            YCore::exception(-1, '删除失败');
        }
        return true;
    }

    /**
     * 发送奖品。
     * -- Example start --
     * ## 话费格式 ##
     * $data = [
     *      'channel' => '微信', // 支付宝、聚合
     *      'sn'      => '流水号',
     * ];
     *
     * ## Q币格式 ##
     * $data = [
     *      'channel' => '微信', // 支付宝、聚合,
     *      'sn'      => '流水号',
     * ];
     *
     * ## 实物 ##
     * $data = [
     *      'express_name' => '快递公司名称',
     *      'express_sn'   => '快递公司单号',
     *      'express_time' => '快递发送时间'
     * ];
     *
     * -- Example end --
     * @param number $admin_id 管理员ID。
     * @param number $id 中奖记录ID。
     * @param number $data 发送奖品相关信息。
     * @return boolean
     */
    public static function sendAward($admin_id, $id, array $data) {
        $lucky_prize_model = new GmLuckyPrize();
        $result = $lucky_prize_model->fetchOne([], ['id' => $id, 'status' => 1]);
        if (empty($result)) {
            YCore::exception(-1, '中奖记录不存在');
        }
        if ($result['goods_type'] == self::GOODS_TYPE_NO) {
            YCore::exception(-1, '未中奖的记录不能操作');
        }
        if ($result['goods_type'] == self::GOODS_TYPE_JB) {
            YCore::exception(-1, '金币奖品不需要手动发奖');
        }
        if ($result['is_send'] == 1) {
            $diff_time = $_SERVER['REQUEST_TIME'] - $result['send_time'];
            if ($diff_time > 86400) {
                YCore::exception(-1, '超过24小时的记录不能修改');
            }
        }
        if (strlen($result['get_info']) === 0) {
            YCore::exception(-1, '用户还未填写领奖信息');
        }
        if (empty($data)) {
            YCore::exception(-1, '必须填写奖励发送凭证信息');
        }
        switch ($result['goods_type']) {
            case self::GOODS_TYPE_HF: // 话费充值要填写流水号之类的数据。
            case self::GOODS_TYPE_QB:
                if (!isset($data['channel']) || strlen($data['channel']) == 0) {
                    YCore::exception(-1, '渠道必须填写');
                }
                if (!isset($data['sn']) || strlen($data['sn']) == 0) {
                    YCore::exception(-1, '流水号必须填写');
                }
                $data = [
                    'channel' => $data['channel'],
                    'sn'      => $data['sn']
                ];
                break;
            case self::GOODS_TYPE_SW:
                if (!isset($data['express_name']) || strlen($data['express_name']) == 0) {
                    YCore::exception(-1, '快递公司名称必须填写');
                }
                if (!isset($data['express_sn']) || strlen($data['express_sn']) == 0) {
                    YCore::exception(-1, '快递单号必须填写');
                }
                if (!isset($data['express_time']) || strlen($data['express_time']) == 0) {
                    YCore::exception(-1, '快递发货时间必须填写');
                }
                if (!Validator::is_date($data['express_time'], 'Y-m-d H:i:s')) {
                    YCore::exception(-1, '快递发货时间格式错误');
                }
                $data = [
                    'express_name' => $data['express_name'],
                    'express_time' => $data['express_time'],
                    'express_sn'   => $data['express_sn']
                ];
                break;
        }
        $data = [
            'is_send'       => 1,
            'send_time'     => $_SERVER['REQUEST_TIME'],
            'modified_by'   => $admin_id,
            'modified_time' => $_SERVER['REQUEST_TIME'],
            'send_info'     => json_encode($data)
        ];
        $ok = $lucky_prize_model->update($data, ['id' => $id, 'status' => 1]);
        if (!$ok) {
            YCore::exception(-1, '奖励发送失败');
        }
        return true;
    }

    /**
     * 用户设置领奖信息。
     * -- Example start --
     * ## 话费 ##
     * $data = [
     *      'mobilephone' => '手机号码'
     * ];
     *
     * ## Q币 ##
     * $data = [
     *      'qq' => 'QQ号'
     * ];
     *
     * ## 实物 ##
     * $data = [
     *      'address_id' => '收货地址ID'
     * ];
     * -- Example end --
     * @param number $user_id 用户ID。
     * @param number $id 中奖记录ID。
     * @param array $data 领奖信息。
     * @return boolean
     */
    public static function setGetInfo($user_id, $id, $data) {
        $lucky_prize_model = new GmLuckyPrize();
        $result = $lucky_prize_model->fetchOne([], ['id' => $id, 'user_id' => $user_id, 'status' => 1]);
        if (empty($result)) {
            YCore::exception(-1, '中奖记录不存在');
        }
        if ($lucky_prize_model['goods_type'] == self::GOODS_TYPE_NO) {
            YCore::exception(-1, '未中奖不能操作');
        }
        if ($lucky_prize_model['goods_type'] == self::GOODS_TYPE_JB) {
            YCore::exception(-1, '金币奖品不需要操作');
        }
        if ($lucky_prize_model['is_send'] == 1) {
            YCore::exception(-1, '奖品已经发送，不能执行操作');
        }
        switch ($result['goods_type']) {
            case self::GOODS_TYPE_HF:
                if (!isset($data['mobilephone']) || strlen($data['mobilephone']) == 0) {
                    YCore::exception(-1, '手机号码必须填写');
                }
                if (!Validator::is_mobilephone($data['mobilephone'])) {
                    YCore::exception(-1, '手机号码格式错误');
                }
                $data = [
                    'mobilephone' => $data['mobilephone']
                ];
                break;
            case self::GOODS_TYPE_QB:
                if (!isset($data['qq']) || strlen($data['qq']) == 0) {
                    YCore::exception(-1, 'QQ号码必须填写');
                }
                if (!Validator::is_qq($data['qq'])) {
                    YCore::exception(-1, 'QQ号码格式错误');
                }
                $data = [
                    'qq' => $data['qq']
                ];
                break;
            case self::GOODS_TYPE_SW:
                if (!isset($data['address_id']) || strlen($data['address_id']) == 0) {
                    YCore::exception(-1, '收货地址必须填写');
                }
                $address_model = new MallUserAddress();
                $address_info  = $address_model->fetchOne([], ['address_id' => $data['address_id'], 'status' => 1, 'user_id' => $user_id]);
                if (empty($address_info)) {
                    YCore::exception(-1, '收货地址不存在');
                }
                $district_model = new District();
                $district_info  = $district_model->fetchOne([], ['district_id' => $address_info['district_id']]);
                $data = [
                    'realname'      => $address_info['realname'],
                    'zipcode'       => $address_info['zipcode'],
                    'mobilephone'   => $address_info['mobilephone'],
                    'address'       => $address_info['address'],
                    'province_name' => $district_info['province_name'],
                    'city_name'     => $district_info['city_name'],
                    'district_name' => $district_info['district_name'],
                    'street_name'   => $district_info['street_name']
                ];
                break;
            default:
                YCore::exception(-1, '服务器异常');
                break;
        }
        $data = [
            'get_info'      => json_encode($data),
            'modified_by'   => $user_id,
            'modified_time' => $_SERVER['REQUEST_TIME']
        ];
        $ok = $lucky_prize_model->update($data, ['id' => $id, 'user_id' => $user_id, 'status' => 1]);
        if (!$ok) {
            YCore::exception(-1, '操作失败');
        }
        return true;
    }
}