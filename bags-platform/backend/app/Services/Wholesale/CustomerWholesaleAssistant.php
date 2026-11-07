<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Quotation;
use App\Services\Ai\EntityResolver;
use App\Services\QuotationService;
use App\Services\SettingService;
use App\Services\WholesalePaymentNoticeService;
use App\Services\WholesaleQuickOrderService;
use Illuminate\Support\Facades\Hash;

/**
 * Customer wholesale assistant for official WhatsApp channel.
 * Staff tools are excluded, and image OCR text is treated strictly as data rather than instructions.
 */
final class CustomerWholesaleAssistant
{
    public function __construct(
        private QuotationService $quotations,
        private WholesalePaymentNoticeService $notices,
        private SettingService $settings,
        private WholesaleOutbox $outbox,
        private WholesaleQuickOrderService $quickOrder,
    ) {}

    public function reply(Customer $customer, string $text, ?string $buttonId = null): string
    {
        if (! $this->allowed($customer)) {
            return 'الحساب ده مش تاجر جملة معتمد.';
        }
        $channel = $this->channel($customer);
        if ($channel->verified_at === null) {
            return $this->verify($channel, $text);
        }
        if ($buttonId !== null && str_starts_with($buttonId, 'accept:')) {
            return $this->acceptButton($customer, $buttonId);
        }
        $text = trim($text);
        if (preg_match('/\b(approve_payment_notice|price_quotation|set_credit_limit)\b/u', $text) === 1) {
            return 'الأداة دي مش متاحة في قناة التاجر.';
        }
        if (str_contains($text, 'اعتمد الإشعار') || str_contains($text, 'اعتمد الاشعار')) {
            return 'اعتماد الإشعار من صورة أو نص مش بيتنفذ. الموظف هو اللي بيراجع الإشعار.';
        }

        $receipt = $this->parseReceiptText($text);
        if ($receipt !== null) {
            return 'سجلت تحويل '.$receipt['amount'].' مرجع '.$receipt['reference'].($receipt['bank'] !== '' ? ' ('.$receipt['bank'].')' : '').'. أربطه بأقرب طلب؟ [تأكيد] [على الحساب]';
        }

        return match (true) {
            str_contains($text, 'رصيد') || str_contains($text, 'عليا كام') => $this->balance($customer),
            str_contains($text, 'كشف') => $this->statement($customer),
            str_starts_with($text, 'فاتورة') => $this->invoice($customer, $text),
            str_contains($text, 'طلبي') => $this->orderStatus($customer),
            str_contains($text, 'عيد') => $this->reorder($customer),
            str_contains($text, 'كلم') => 'هحوّلك لموظف المبيعات، وهبعتلك رسالة لو الرد اتأخر.',
            default => 'أقدر أساعدك في الرصيد، كشف الحساب، الفاتورة، حالة الطلب، أو إعادة آخر طلب.',
        };
    }

    public function acceptHash(Quotation $quotation): string
    {
        return hash('sha256', $quotation->id.'|'.$quotation->grand_total.'|'.$quotation->revision);
    }

    private function allowed(Customer $customer): bool
    {
        return in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
            && $customer->status === CustomerStatus::Approved;
    }

    private function channel(Customer $customer): CustomerChannel
    {
        return CustomerChannel::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'provider' => 'whatsapp_cloud'],
            ['phone' => (string) ($customer->whatsapp_phone ?: $customer->phone), 'verification_status' => 'unverified'],
        );
    }

    private function verify(CustomerChannel $channel, string $text): string
    {
        if (
            preg_match('/^\d{6}$/', $text) === 1
            && $channel->verification_code_hash !== null
            && $channel->verification_expires_at !== null
            && $channel->verification_expires_at->isFuture()
            && Hash::check($text, $channel->verification_code_hash)
        ) {
            $channel->update([
                'verified_at' => now(),
                'verification_status' => 'verified',
                'verification_code_hash' => null,
            ]);

            return 'تم ربط الرقم. اسأل عن رصيدك أو كشف الحساب.';
        }
        $code = (string) random_int(100000, 999999);
        $channel->update([
            'verification_code_hash' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes(10),
        ]);
        $payload = ['channel_id' => $channel->id];
        if (! $this->settings->getBool('ai_wholesale_shadow_mode', true)) {
            $payload['code'] = $code;
        }
        $this->outbox->queue(
            'customer-otp:'.$channel->id.':'.now()->format('YmdHi'),
            'customer_whatsapp',
            $channel->customer_id,
            'customer_otp',
            $payload,
            deliver: true,
        );

        return 'بعتنا رمز التحقق على واتساب. ابعت الرمز المكوّن من 6 أرقام.';
    }

    private function acceptButton(Customer $customer, string $buttonId): string
    {
        $parts = explode(':', $buttonId);
        $quotation = Quotation::query()
            ->whereKey((int) ($parts[1] ?? 0))
            ->where('customer_id', $customer->id)
            ->first();
        if ($quotation === null || ! hash_equals($this->acceptHash($quotation), (string) ($parts[2] ?? ''))) {
            return 'السعر اتغير بعد الرسالة. هبعتلك العرض الجديد قبل أي قبول.';
        }
        try {
            $accepted = $this->quotations->accept($quotation);
        } catch (BusinessException $e) {
            return $e->getMessage();
        }

        return 'اتقبل العرض واتعمل الطلب '.($accepted->converted_order_id ?? '');
    }

    private function balance(Customer $customer): string
    {
        $debt = $this->notices->outstandingBalance($customer);
        $available = max(0, round((float) $customer->credit_limit - $debt, 2));
        $due = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_status', 'unpaid')
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->value('due_at');

        return 'المديونية '.number_format($debt, 2, '.', '').' والمتاح من الحد '.number_format($available, 2, '.', '')
            .($due ? ' وأقرب استحقاق '.$due : '');
    }

    private function statement(Customer $customer): string
    {
        $statement = $this->notices->statement($customer);
        $count = count($statement['entries'] ?? []);

        return 'كشف الحساب فيه '.$count.' حركة. الإرسال التلقائي بيحصل في وضع المراقبة لحد ما القناة تتفعل.';
    }

    private function invoice(Customer $customer, string $text): string
    {
        $number = trim(str_replace('فاتورة', '', $text));
        $order = Order::query()->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('order_number', $number)->orWhere('id', (int) $number))
            ->first();
        if ($order === null) {
            return 'الفاتورة مش موجودة على الحساب ده.';
        }

        return 'فاتورة '.$order->order_number.' جاهزة من بوابة الجملة.';
    }

    private function orderStatus(Customer $customer): string
    {
        $order = Order::query()->where('customer_id', $customer->id)->latest('id')->first();
        if ($order === null) {
            return 'مفيش طلبات على الحساب.';
        }
        $history = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->first();
        $status = $history?->to_status instanceof \BackedEnum
            ? $history->to_status->value
            : ($history !== null ? (string) $history->to_status : ($order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status));

        return 'آخر طلب '.$order->order_number.' حالته '.$status.'.';
    }

    private function reorder(Customer $customer): string
    {
        $order = Order::query()->with('items')->where('customer_id', $customer->id)->latest('id')->first();
        if ($order === null) {
            return 'مفيش طلب نعيده.';
        }
        $result = $this->quickOrder->reorderToCart($customer, $order);
        $added = count($result['added']);
        $skipped = count($result['skipped']);

        return 'اتضافت '.$added.' بند للسلة من آخر طلب'
            .($skipped > 0 ? ' واتشال '.$skipped.' لنقص المخزون أو الإيقاف' : '')
            .'. راجع السلة قبل التأكيد.';
    }

    /**
     * @return array{amount: string, reference: string, bank: string}|null
     */
    private function parseReceiptText(string $text): ?array
    {
        if (! str_contains($text, 'تحويل') && ! str_contains($text, 'ايصال') && ! str_contains($text, 'إيصال')) {
            return null;
        }
        $normalized = EntityResolver::normalizeDigits($text);
        if (! preg_match('/مرجع\s*(\d+)/u', $normalized, $ref)) {
            return null;
        }
        preg_match_all('/\d+(?:[.,]\d+)?/u', $normalized, $numbers);
        $amount = (string) ($numbers[0][0] ?? '');
        if ($amount === '' || $amount === $ref[1]) {
            $amount = (string) ($numbers[0][1] ?? $numbers[0][0] ?? '');
        }
        if ($amount === '') {
            return null;
        }
        $bank = '';
        if (preg_match('/من\s+([^0-9]+?)(?:\s+مرجع|$)/u', trim($normalized), $bankMatch)) {
            $bank = trim($bankMatch[1]);
        }

        return ['amount' => $amount, 'reference' => $ref[1], 'bank' => $bank];
    }
}
