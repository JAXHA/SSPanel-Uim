<?php
/**
 * 支付宝微信检测监听 间隔为10秒
 * cookie失效会通过email通知
 * 请务必设置好邮箱配置
 * User: chen yun.9in.info
 * Date: 9/12/18
 * Time: 12:33 PM
 */

namespace App\Services\Gateway;

use App\Models\Paylist;
use App\Services\Mail;
use App\Services\View;
use App\Services\Auth;

class ChenPay extends AbstractPayment
{
    private $config = [];
    private $listenInterval = 10; // 运行间隔

    /**
     * 初始化
     */
    public function __construct()
    {
        $data = [];
        foreach (\App\Models\Config::get() as $item) $data[$item->name] = $item->value;
        $this->config = $data;
    }

    /**
     * 获取config值
     * @param bool $name
     * @return array|mixed
     */
    public function getConfig($name = false)
    {
        if ($name) return $this->config[$name];
        else return $this->config;
    }

    /**
     * 设置数据库中的config
     * @param $name
     * @param $value
     * @return mixed
     */
    public function setConfig($name, $value)
    {
        \App\Models\Config::where('name', $name)->update(['value' => $value]);
        $this->config[$name] = $value;
        return $value;
    }

    /**
     * 注入HTML
     * @return mixed
     */
    public function getPurchaseHTML()
    {
        return View::getSmarty()->assign("config", $this->config)
            ->assign('QRcodeUrl', $this->config->getConfig('AliPay_QRcode'))
            ->assign('WxQRcodeUrl', $this->config->getConfig('WxPay_QRcode'))
            ->fetch("user/chenPay.tpl");
    }

    /**
     * 获取订单信息
     * @param $request
     * @param $response
     * @param $args
     * @return false|string
     */
    public function getStatus($request, $response, $args)
    {
        $id = $request->getParam('id');
        if (!$id) return json_encode(['ret' => 0, 'msg' => '请输入Id']);
        $order = Paylist::find($id);
        $order->ret = 1;
        return json_encode($order);
    }

    /**
     * 生成订单
     * @param $request
     * @param $response
     * @param $args
     * @return false|string
     */
    public function purchase($request, $response, $args)
    {
        $type = $request->getParam('type');
        $amount = $request->getParam('fee');
        $url = $request->getParam('url');
        if (!is_numeric($amount) || !is_numeric($type)) return json_encode(['ret' => 0, 'msg' => '请输入正确金额']);
        elseif ($amount <= 0) return json_encode(['ret' => 0, 'msg' => '请输入正确金额']);

        $user = Auth::getUser();
        if (Paylist::where('status', 0)->where('datetime', '>', time())->first()) {
            $newOrder = new Paylist();
            $newOrder->userid = $user->id;
            $newOrder->total = $amount;
            $newOrder->datetime = time() + 3 * 60; // 有效时间
            $newOrder->sys_sn = rand(100000, 999999) . $user->id . $newOrder->datetime;
            $newOrder->type = $type;
            $newOrder->url = $url;
            $newOrder->save();
            $newOrder->ret = 1;
        } else $newOrder = ['msg' => '正在排队中，请稍后再试！', 'ret' => 0];
        return json_encode($newOrder);
    }

    /**
     * 手动关闭排队机制 & 删除订单
     * @param $request
     * @return false|string
     */
    public function orderDelete($request)
    {
        $id = $request->getParam('id');
        if (!$id) return json_encode(['ret' => 0, 'msg' => '请输入Id']);
        $user = Auth::getUser();
        return json_encode(['ret' => Paylist::where("id", $id)->where('status', 0)->where('userid', $user->id)->delete()]);
    }

    /**
     * 后台支付配置
     * @return mixed
     */
    public function editConfig()
    {
        return View::getSmarty()->assign('payConfig', $this->config)->display('admin/payEdit.tpl');
    }

    /**
     * 后台保存配置
     * @param $request
     * @return false|string
     */
    public function saveConfig($request)
    {
        $this->setConfig('Notice_EMail', $request->getParam('Notice_EMail'));
        $this->setConfig('AliPay_QRcode', $request->getParam('AliPay_QRcode'));
        $this->setConfig('AliPay_Cookie', $request->getParam('AliPay_Cookie'));
        $this->setConfig('WxPay_QRcode', $request->getParam('WxPay_QRcode'));
        $this->setConfig('WxPay_Cookie', $request->getParam('WxPay_Cookie'));
        $this->setConfig('WxPay_Url', $request->getParam('WxPay_Url'));
        $this->setConfig('WxPay_SyncKey', '');
        $this->setConfig('Pay_Price', $request->getParam('Pay_Price'));
        $this->setConfig('AliPay_Status', $request->getParam('AliPay_Status'));
        $this->setConfig('WxPay_Status', $request->getParam('WxPay_Status'));
        return json_encode(['ret' => 1, 'msg' => '编辑成功！']);
    }

    /**
     * @param int $type
     * @param int $isError
     */
    public function mail($type = 1, $isError = 0)
    {
        $time = date('Y-m-d H:i:s');
        $name = $type == 1 ? '支付宝' : '微信';
        $this->setConfig($type == 1 ? 'AliPay_Status' : 'WxPay_Status', 0);
        Mail::getClient()->send($this->getConfig('Notice_EMail'), 'LOG报告监听' . $name . 'COOKIE出现问题',
            "LOG提醒你，{$name}COOKIE" . ($isError ? "出现问题，请务必尽快更新COOKIE" : "成功运行") . "。<br>LOG记录时间：$time", []);
    }

    /**
     * 发送错误email
     * @param int $type
     */
    public function sendMail($type = 1)
    {
        if ($this->getConfig('AliPay_Status') == 1 || $this->getConfig('WxPay_Status') == 1)
            $this->mail($type, 1);
    }

    /**
     * 发生成功email
     * @param int $type
     */
    public function sendSunMail($type = 1)
    {
        if ($this->getConfig('AliPay_Status') == 0 || $this->getConfig('WxPay_Status') == 0)
            $this->mail($type);
    }

    /**
     * 监听支付宝
     */
    public function AliPayListen()
    {
        $AliCookie = $this->getConfig('AliPay_Cookie');
        $GLOBALS['AliSum'] = 1;
        \ChenPay\Pay::Listen($this->listenInterval, function () use ($AliCookie) {
            try {
                $run = (new \ChenPay\AliPay($AliCookie))->getData()->DataHandle();
                $tradeAll = Paylist::where('status', 0)->where('datetime', '>', time())->orderBy('id', 'desc')->get();
                foreach ($tradeAll as $item) {
                    $order = $run->DataContrast($item->total, $item->datetime);
                    if ($order) static::postPayment($item->id, 'chenPay支付' . $order);
                }
                Paylist::where('status', 0)->where('datetime', '<', time())->delete();
                echo $GLOBALS['AliSum'] . "次运行\n";
                $this->sendSunMail(1);
                $GLOBALS['AliSum']++;
            } catch (\ChenPay\PayException\PayException $e) {
                if ($e->getCode() == 445) $this->sendMail(1);
                echo $e->getMessage() . "\n";
            }
        });
    }

    /**
     * 监听微信
     */
    public function WxPayListen()
    {
        $WxCookie = $this->getConfig('WxPay_Cookie');
        $WxUrl = $this->getConfig('WxPay_Url');
        $GLOBALS['WxSum'] = 1;
        $GLOBALS['syncKey'] = false;
        \ChenPay\Pay::Listen($this->listenInterval, function () use ($WxCookie, $WxUrl) {
            try {
                $run = (new \ChenPay\WxPay($WxCookie))->getData($WxUrl, $GLOBALS['syncKey'])->DataHandle();
                $GLOBALS['syncKey'] = $run->syncKey;
                $tradeAll = Paylist::where('status', 0)->where('datetime', '>', time())->orderBy('id', 'desc')->get();
                foreach ($tradeAll as $item) {
                    $order = $run->DataContrast($item->total, $item->datetime);
                    if ($order) static::postPayment($item->id, 'chenPay支付' . $order);
                }
                Paylist::where('status', 0)->where('datetime', '<', time())->delete();
                echo $GLOBALS['WxSum'] . "次运行\n";
                $this->sendSunMail(2);
                $GLOBALS['WxSum']++;
            } catch (\ChenPay\PayException\PayException $e) {
                if ($e->getCode() == 445) $this->sendMail(2);
                echo $e->getMessage() . "\n";
            }
        });
    }

    public function getReturnHTML($request, $response, $args)
    {
    }

    public function notify($request, $response, $args)
    {
    }

    public function sign($data)
    {
    }

    public function setMethod($method)
    {
    }

    public function setNotifyUrl()
    {
    }

    public function setReturnUrl()
    {
    }

    public function init()
    {
    }
}