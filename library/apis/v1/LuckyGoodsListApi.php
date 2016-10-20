<?php
/**
 * 抽奖商品列表。
 * @author winerQin
 * @date 2016-10-20
 */

namespace apis\v1;

use apis\BaseApi;
use services\LuckyService;

class LuckyGoodsListApi extends BaseApi {

    /**
     * 逻辑处理。
     *
     * @see Api::runService()
     * @return bool
     */
    protected function runService() {
        $goods_list = LuckyService::getLuckyGoodsList();
        $this->render(0, 'ok', $goods_list);
    }

}