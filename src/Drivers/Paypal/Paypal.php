<?php

namespace Shetabit\Multipay\Drivers\Paypal;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Paypal extends Driver
{
    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Paypal constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \SoapFault
     */
    public function purchase()
    {
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        $data = [
            'MerchantID' => $this->settings->merchantId,
            'Amount' => $this->invoice->getAmount(),
            'CallbackURL' => $this->settings->callbackUrl,
            'Description' => $description,
            'AdditionalData' => $this->invoice->getDetails()
        ];

        $client = new \SoapClient($this->getPurchaseUrl(), ['encoding' => 'UTF-8']);
        $result = $client->PaymentRequest($data);

        if ($result->Status != 100 || empty($result->Authority)) {
            // some error has happened
            $message = $this->translateStatus($result->Status);
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($result->Authority);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->getPaymentUrl();

        if (strtolower($this->getMode()) === 'zaringate') {
            $payUrl = str_replace(':authority', $transactionId, $paymentUrl);
        } else {
            $payUrl = $paymentUrl.$transactionId;
        }

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify() : ReceiptInterface
    {
        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');
        $status = Request::input('Status');

        $data = [
            'MerchantID' => $this->settings->merchantId,
            'Authority' => $authority,
            'Amount' => $this->invoice->getAmount(),
        ];

        if ($status != 'OK') {
            throw new InvalidPaymentException('عملیات پرداخت توسط کاربر لغو شد.');
        }

        $client = new \SoapClient($this->getVerificationUrl(), ['encoding' => 'UTF-8']);
        $result = $client->PaymentVerification($data);

        if ($result->Status != 100) {
            $message = $this->translateStatus($result->Status);
            throw new InvalidPaymentException($message, (int)$result->Status);
        }

        return $this->createReceipt($result->RefID);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    public function createReceipt($referenceId): \Shetabit\Multipay\Receipt
    {
        return new Receipt('zarinpal', $referenceId);
    }

    /**
     * Convert status to a readable message.
     *
     * @param $status
     *
     * @return mixed|string
     */
    private function translateStatus($status): string
    {
        $translations = [
            "-1" => "اطلاعات ارسال شده ناقص است.",
            "-2" => "IP و يا مرچنت كد پذيرنده صحيح نيست",
            "-3" => "با توجه به محدوديت هاي شاپرك امكان پرداخت با رقم درخواست شده ميسر نمي باشد",
            "-4" => "سطح تاييد پذيرنده پايين تر از سطح نقره اي است.",
            "-11" => "درخواست مورد نظر يافت نشد.",
            "-12" => "امكان ويرايش درخواست ميسر نمي باشد.",
            "-21" => "هيچ نوع عمليات مالي براي اين تراكنش يافت نشد",
            "-22" => "تراكنش نا موفق ميباشد",
            "-33" => "رقم تراكنش با رقم پرداخت شده مطابقت ندارد",
            "-34" => "سقف تقسيم تراكنش از لحاظ تعداد يا رقم عبور نموده است",
            "-40" => "اجازه دسترسي به متد مربوطه وجود ندارد.",
            "-41" => "اطلاعات ارسال شده مربوط به AdditionalData غيرمعتبر ميباشد.",
            "-42" => "مدت زمان معتبر طول عمر شناسه پرداخت بايد بين 30 دقيه تا 45 روز مي باشد.",
            "-54" => "درخواست مورد نظر آرشيو شده است",
            "101" => "عمليات پرداخت موفق بوده و قبلا PaymentVerification تراكنش انجام شده است.",
        ];

        $unknownError = 'خطای ناشناخته رخ داده است.';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }

    /**
     * Retrieve purchase url
     */
    protected function getPurchaseUrl() : string
    {
        $mode = $this->getMode();

        return match ($mode) {
            'sandbox' => $this->settings->sandboxApiPurchaseUrl,
            'zaringate' => $this->settings->zaringateApiPurchaseUrl,
            // default: normal
            default => $this->settings->apiPurchaseUrl,
        };
    }

    /**
     * Retrieve Payment url
     */
    protected function getPaymentUrl() : string
    {
        $mode = $this->getMode();

        return match ($mode) {
            'sandbox' => $this->settings->sandboxApiPaymentUrl,
            'zaringate' => $this->settings->zaringateApiPaymentUrl,
            // default: normal
            default => $this->settings->apiPaymentUrl,
        };
    }

    /**
     * Retrieve verification url
     */
    protected function getVerificationUrl() : string
    {
        $mode = $this->getMode();

        return match ($mode) {
            'sandbox' => $this->settings->sandboxApiVerificationUrl,
            'zaringate' => $this->settings->zaringateApiVerificationUrl,
            // default: normal
            default => $this->settings->apiVerificationUrl,
        };
    }

    /**
     * Retrieve payment mode.
     */
    protected function getMode() : string
    {
        return strtolower($this->settings->mode);
    }
}
