<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Components\CoinPaymentsAPI;
use Illuminate\Support\Facades\Validator;
use App\Models\TransactionModel;

class TransactionProccess extends Controller
{
    private $setup;
    private $basicinfo;
    private $amount;
    private $coinpaymentapi;
    public function __construct()
    {
        $this->coinpaymentapi = new CoinPaymentsAPI();
        $this->setup = $this->coinpaymentapi->Setup(env('PRIVATE_KEY'), env('PUBLIC_KEY'));
        $this->basicinfo = ($this->coinpaymentapi->GetBasicProfile())['result']['public_name'];
    }
    public function prepare(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'amount' => 'required|numeric',
            'ipn_url' => 'required|url',
            'address' => 'required|string',
            'rcurrency' => 'required|string',
            'scurrency' => 'required|string',
            'item' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        } else {
            return $this->CreateTransaction([
                'address' => "",
                'item' => $request->item,
                'amount' => $request->amount,
                'ipn_url' => $request->ipn_url,
                'buyer_email' => $request->email,
                'currency1' => $request->scurrency,
                'currency2' => $request->rcurrency,
            ]);
        }
    }
    public function CreateTransaction($data)
    {
        $result = $this->coinpaymentapi->CreateTransaction($data);
        if ($result['error'] == "ok") {
            return TransactionModel::create([
                'amount' => $result['result']['amount'],
                'gateway_id' => $result['result']['txn_id'],
                'gateway_url' => $result['result']['status_url'],
                'entered_amount' => $data['amount'],
                'from_currency' => $data['currency1'],
                'to_currency' => $data['currency2'],
                'email' => $data['buyer_email'],
            ]);
        } else {
            return $result['error'];
            die();
        }
    }
    public function webhook(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'txn_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        } else {
            $payment = TransactionModel::where('gateway_id', $request->txn_id)->count();
            if (intval($payment) != 0) {
                $payment = TransactionModel::where('gateway_id', $request->txn_id)->first();
                $order_currency = $payment->to_currency; // BTC
                $order_total = $payment->amount; // BTC
                if (!isset($_POST['ipn_mode']) && $_POST['ipn_mode'] != 'hmac') {
                    return response()->json([
                        'error' => 'ipn mode is not hmac',
                    ]);
                }
                if (!isset($_SERVER['HTTP_HMAC']) || empty($_SERVER['HTTP_HMAC'])) {
                    return response()->json([
                        'error' => 'No HMAC Signature Sent',
                    ]);
                }
                if (!isset($_POST['merchant']) || $_POST['merchant'] != trim(env('Merchant_ID'))) {
                    return response()->json([
                        'error' => 'No Or Incorrect Merchant id',
                    ]);
                }
                $req = file_get_contents('php://input');
                if ($req === false || empty($req)) {
                    return response()->json([
                        'error' => 'Error in Reading Post Data',
                    ]);
                }
                $HMAC = hash_hmac('sha512', $req, trim(env('IPN_SECRET')));
                if (!hash_equals($HMAC, $_SERVER['HTTP_HMAC'])) {
                    return response()->json([
                        'error' => 'HMAC Signature is not Match',
                    ]);
                }
                $amount1 = floatval($request->amount1); // In USD
                $amount2 = floatval($request->amount2); // In BTC
                $currency1 = $request->currency1; // USD
                $currency2 = $request->currency2; // BTC
                $status = intval($request->status);

                if ($currency2 != $order_currency) {
                    return response()->json([
                        'error' => 'Currency Mismatch',
                    ]);
                }
                if ($amount2 < $order_total) {
                    return response()->json([
                        'error' => 'Amount is Lesser Than Total',
                    ]);
                }
                if ($status >= 100 || $status == 2) {
                    $payment->status = "success";
                    $payment->save();
                } elseif ($status < 0) {
                    $payment->status = "error";
                    $payment->save();
                } else {
                    $payment->status = "pending";
                    $payment->save();
                }
            }
        }
    }
}
