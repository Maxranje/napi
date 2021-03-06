<?php

class Service_Pay_Order {

    private $daoOrder ;

    private $daoCourse;

    private $daoUser;

    private $pay;

    private $nowtime ;

    private $tradeid ;

    const PAY_TYPE_WX = 'wx';

    const PAY_TYPE_ALI = 'ali';

    const PAY_TYPE = [
        self::PAY_TYPE_WX => '微信支付',
        self::PAY_TYPE_ALI => '支付宝支付',
    ];

    public function __construct() {
        $this->daoOrder = new Dao_User_Mysql_Order();
        $this->daoCourse = new Dao_Course_Mysql_Course();
        $this->daoUser   = new Dao_User_Mysql_User();
        $this->pay       = Zy_Helper_Pay::getInstance();
        $this->nowtime  = time();
    }

    public function isOftenPay($userid) {
        $historyOrder = $this->daoOrder->getRecordByConds(['userid' => $userid], $this->daoOrder->arrFieldsMap, null, ['order by id desc']);
        if (!empty($historyOrder) && $this->nowtime -$historyOrder['createtime'] <= 60) {
            return true;
        }
        return false;
    }

    public function payOrder ($userid, $courseid, $paytype) {

        $user = $this->daoUser->getRecordByConds(['userid' => $userid], $this->daoUser->arrFieldsMap);
        if (empty($user)) {
            throw new Zy_Core_Exception(405, '用户信息不存在, 请重新登陆');
        }
        if ($user['status'] == Service_Account_User::USER_STATUS_BLOCK) {
            throw new Zy_Core_Exception(405, '您的账户已被锁定, 请联系工作人员解封');
        }

        $course = $this->daoCourse->getRecordByConds(['courseid' => $courseid], ['courseid', 'price', 'isvip','status']);
        if (empty($course) || $course['status'] == Service_Course_Lists::COURSE_STATUS_OFFLINE) {
            throw new Zy_Core_Exception(405, '课程已下线');
        }

        $price = $course['price'];
        $realprice = $course['price'];
        if ($course['isvip'] == 1 && $user['isvip'] ==1 && $user['discount']> 0 && $user['discount'] < 100) {
            $realprice = ceil((intval($course['price']) / 100 ) * intval($user['discount']));
        }

        $this->tradeid = Zy_Helper_Guid::toString() ;

        $data = [
            "userid"  => $userid , 
            "courseid"  => $courseid , 
            "status"  => 2, 
            "tradeid"  => $this->tradeid,
            "productid"  => '110000202011000011000000000' . $course['courseid'] , 
            "price"  =>  $price, 
            "realprice" => $realprice,
            "paytype"  => 'wx',
            "openid"  => "",
            "banktype" => "",
            "createtime"  => time() , 
            "updatetime"  => time() , 
        ];

        if ($paytype == self::PAY_TYPE_WX) {
            $qrurl = $this->pay->wxpayorder($data['tradeid'], $data['productid'], $realprice);
            if ($qrurl == false){
                return false;
            }
            $this->daoOrder->insertRecords($data);

        } else {
            $qrurl = $this->pay->alipayorder();
        }

        return $qrurl;
    }

    public function getTradeid () {
        return empty($this->tradeid) ? "" : $this->tradeid;
    }

    public function getOrderTotal ($userid) {
        if (empty($userid)) {
            throw new Zy_Core_Exception(405, '请先登陆');
        }

        $arrConds = [
            'userid' => $userid,
        ];

        $total = $this->daoOrder->getCntByConds($arrConds);
        return $total;
    }

    public function getOrderLists ($userid, $pn = 0, $rn = 20) {
        if (empty($userid)) {
            throw new Zy_Core_Exception(405, '请先登陆');
        }

        $arrConds = [
            'userid' => $userid,
        ];

        $arrFields = $this->daoOrder->simpleFields;

        $arrAppends = [
            'order by id desc',
            "limit {$pn}, {$rn} ",
        ];

        $lists = $this->daoOrder->getListByConds($arrConds, $arrFields, $arrAppends);
        if (empty($lists)) {
            return [];
        }

        $courseids = array_column($lists, 'courseid');
        $orderList = array_column($lists, null, 'courseid');

        $arrConds = [
            'courseid in (' . implode(',', $courseids) . ')', 
            'status = 1',
        ];
        $arrFields = $this->daoCourse->simpleFields;

        $lists = $this->daoCourse->getListByConds($arrConds, $arrFields);

        foreach ($lists as $index => $course) {
            $course['createtime'] = date('Y年m月d日', $course['createtime']);
            $course['paystatus']  = empty($orderList[$course['courseid']]) ? 4 : $orderList[$course['courseid']];
            $lists[$index] = $course;
        }

        return $lists;
    }

    public function checkOrder ($userid, $tradeid) {
        $arrConds = [
            'userid' => $userid,
            'tradeid' => $tradeid,
        ];

        $record = $this->daoOrder->getRecordByConds($arrConds, ['status']);
        return empty($record['status']) || $record['status'] != 1 ? false : true;
    }

    public function callback ($input) {
        $out_trade_no = empty($input['out_trade_no']) ? "" : $input['out_trade_no'];
        if (empty($out_trade_no)) {
            return false;
        }

        $order = $this->daoOrder->getRecordByConds(['tradeid' => $out_trade_no], $this->daoOrder->arrFieldsMap);
        if (empty($order)) {
            return true;
        }

        $data = [
            "status"  => 1, 
            "openid"  => $input['openid'],
            "banktype" => $input['bank_type'],
            "updatetime"  => time() , 
        ];

        $ret = $this->daoOrder->updateByConds(['userid' => $order['userid'] , 'tradeid' => $out_trade_no], $data);
        return true;
    }
}