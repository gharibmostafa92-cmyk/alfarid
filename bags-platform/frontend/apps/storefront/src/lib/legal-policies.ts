import { LEGAL_POLICY_VERSION } from './legal-policy-links';

export { LEGAL_POLICY_VERSION };

export type LegalPolicyId =
  | 'terms'
  | 'privacy'
  | 'returns'
  | 'shipping'
  | 'payments'
  | 'cookies';

export interface LegalPolicySection {
  readonly heading: string;
  readonly headingAr: string;
  readonly paragraphs: readonly string[];
  readonly paragraphsAr: readonly string[];
}

export interface LegalPolicy {
  readonly id: LegalPolicyId;
  readonly slug: string;
  readonly title: string;
  readonly titleAr: string;
  readonly summary: string;
  readonly summaryAr: string;
  readonly sections: readonly LegalPolicySection[];
}

const legalPoliciesV1: LegalPolicy[] = [
  {
    id: 'terms',
    slug: 'terms-and-conditions',
    title: 'Terms & Conditions',
    titleAr: 'الشروط والأحكام',
    summary: 'The rules that apply when you browse the store or place an order.',
    summaryAr: 'القواعد التي تنظم استخدام المتجر وإتمام طلبات الشراء.',
    sections: [
      {
        heading: 'The seller and the store',
        headingAr: 'البائع والمتجر',
        paragraphs: [
          'The seller is the business identified in the seller details shown on this page and in the store checkout. The store provides product information, order placement, and customer support for purchases made through this website.',
          'Before placing an order, review the product description, price, available options, delivery details, and the policies linked at checkout.',
        ],
        paragraphsAr: [
          'البائع هو النشاط التجاري الموضح في بيانات البائع بهذه الصفحة وعند إتمام الطلب. يتيح المتجر عرض المنتجات وتقديم الطلبات وخدمة العملاء للمشتريات التي تتم عبر هذا الموقع.',
          'قبل تأكيد الطلب، راجع وصف المنتج وسعره والاختيارات المتاحة وبيانات التوصيل والسياسات المرتبطة بصفحة الشراء.',
        ],
      },
      {
        heading: 'Products, prices, and orders',
        headingAr: 'المنتجات والأسعار والطلبات',
        paragraphs: [
          'Product pages describe the item’s main characteristics and options. The checkout shows the order total, applicable charges, payment method, and estimated delivery information before you confirm.',
          'An order is submitted when you confirm it at checkout. The completion screen displays a confirmation and the order details available at that time, with an option to save or print the confirmation. Account holders can also review their orders in their account. If an item or price cannot be honored, customer support will contact you to agree on the next step and any payment adjustment.',
          'Where the statutory correction period applies, contact support within seven business days of accepting the remote contract to ask to correct or amend your order, subject to the applicable legal procedures.',
        ],
        paragraphsAr: [
          'توضح صفحة المنتج خصائصه الأساسية والاختيارات المتاحة. وتعرض صفحة الشراء إجمالي الطلب والرسوم المطبقة وطريقة الدفع وموعد التوصيل المتوقع قبل التأكيد.',
          'يُرسل الطلب عند تأكيده في صفحة الشراء، وتظهر لك رسالة تأكيد وبيانات الطلب المتاحة في شاشة إتمام الطلب مع خيار حفظها أو طباعتها. ويمكن لصاحب الحساب مراجعة طلباته من حسابه. إذا تعذر توفير منتج أو تنفيذ السعر المعروض، يتواصل معك فريق الدعم للاتفاق على الخطوة التالية وأي تسوية مالية لازمة.',
          'عندما ينطبق حق تصحيح الطلب، تواصل مع الدعم خلال سبعة أيام عمل من قبول التعاقد عن بُعد لطلب تصحيح أو تعديل بيانات الطلب، وفق الإجراءات القانونية المنطبقة.',
        ],
      },
      {
        heading: 'Your rights and complaints',
        headingAr: 'حقوقك والشكاوى',
        paragraphs: [
          'Nothing in these terms removes consumer rights that apply under Egyptian law, including rights concerning product information, warranties, remote contracts, returns, refunds, and complaints. You may contact the Consumer Protection Agency through its official channels.',
          'Egyptian law applies to orders delivered in Egypt, subject to mandatory consumer protections.',
        ],
        paragraphsAr: [
          'لا تنتقص هذه الشروط من حقوق المستهلك المقررة في القانون المصري، بما فيها الحقوق المتعلقة ببيانات المنتج والضمانات والتعاقد عن بُعد والاسترجاع والاسترداد والشكاوى. ويمكنك التواصل مع جهاز حماية المستهلك عبر قنواته الرسمية.',
          'يخضع الطلب الذي يُسلّم داخل مصر للقانون المصري، مع مراعاة القواعد الآمرة لحماية المستهلك.',
        ],
      },
      {
        heading: 'Using the website',
        headingAr: 'استخدام الموقع',
        paragraphs: [
          'Use accurate contact and delivery details, keep account credentials private, and do not misuse the website or interfere with its operation. Contact customer support if you notice an order or account issue.',
          'We may update these terms for future orders. The version presented and accepted when you place an order remains associated with that order.',
        ],
        paragraphsAr: [
          'استخدم بيانات اتصال وتوصيل صحيحة، وحافظ على سرية بيانات حسابك، ولا تستخدم الموقع بطريقة تضر به أو تعطل تشغيله. تواصل مع الدعم إذا لاحظت مشكلة في طلب أو حساب.',
          'قد نحدّث هذه الشروط للطلبات المستقبلية. وتظل النسخة التي عُرضت عليك وقبلتها عند إتمام الطلب مرتبطة بذلك الطلب.',
        ],
      },
    ],
  },
  {
    id: 'privacy',
    slug: 'privacy-policy',
    title: 'Privacy Policy',
    titleAr: 'سياسة الخصوصية',
    summary: 'How order and account information is used to run the store.',
    summaryAr: 'كيف تُستخدم بيانات الطلب والحساب لتشغيل المتجر وخدمة العملاء.',
    sections: [
      {
        heading: 'Information we use',
        headingAr: 'البيانات التي نستخدمها',
        paragraphs: [
          'Depending on how you use the store, we may process your name, phone number, email address, delivery address, order and return details, account information, and messages you send to customer support.',
          'When an order is placed, we also record the version of the store policies accepted and the server time of that acceptance with the order.',
          'The store also uses limited browser storage for functions such as remembering your language, connecting a guest cart, and resuming an unfinished checkout.',
        ],
        paragraphsAr: [
          'بحسب استخدامك للمتجر، قد نعالج اسمك ورقم هاتفك وبريدك الإلكتروني وعنوان التوصيل وبيانات الطلب أو المرتجع ومعلومات الحساب والرسائل التي ترسلها إلى خدمة العملاء.',
          'وعند تقديم طلب، نسجل إصدار سياسات المتجر الذي وافقت عليه ووقت الموافقة من الخادم ضمن بيانات الطلب.',
          'يستخدم المتجر أيضًا مساحة تخزين محدودة في المتصفح لوظائف مثل تذكر اللغة وربط سلة الزائر واستكمال الشراء غير المكتمل.',
        ],
      },
      {
        heading: 'Why we use it',
        headingAr: 'أسباب الاستخدام',
        paragraphs: [
          'We use order information to confirm and fulfil purchases, arrange delivery, process returns and refunds, answer support requests, protect the service, and meet applicable legal and accounting obligations.',
          'The processing basis depends on the purpose. It may be needed to perform your purchase, meet a legal obligation, protect the store and its customers, or obtain your separate consent when consent is the applicable basis.',
          'We share only the information needed with service providers involved in payment, delivery, hosting, technical monitoring, or customer support. Their access is limited to the service they provide.',
        ],
        paragraphsAr: [
          'نستخدم بيانات الطلب لتأكيد الشراء وتنفيذه وترتيب التوصيل ومعالجة المرتجعات والاستردادات والرد على الدعم وحماية الخدمة والوفاء بالالتزامات القانونية والمحاسبية المنطبقة.',
          'يختلف أساس المعالجة بحسب الغرض، وقد يكون تنفيذ عملية الشراء أو الوفاء بالتزام قانوني أو حماية المتجر وعملائه أو الحصول على موافقة منفصلة عندما تكون هي الأساس المنطبق.',
          'نشارك الحد اللازم من البيانات مع مزودي الخدمات المرتبطين بالدفع أو التوصيل أو الاستضافة أو المراقبة التقنية أو خدمة العملاء، ويقتصر وصولهم على ما تتطلبه الخدمة المقدمة.',
        ],
      },
      {
        heading: 'Retention and your requests',
        headingAr: 'الاحتفاظ بطلباتك وبياناتك',
        paragraphs: [
          'We keep order and invoice records for the periods required by applicable law. Other information is retained only while needed for the purpose described above or to resolve a request or dispute.',
          'You may contact the seller using the details on this page to ask about your personal information, request a correction, or ask us to delete information that we are not required to retain. We may need to verify your identity before acting on a request.',
          'You may also ask about access, restriction, or objection where those rights apply, withdraw consent that was given for an optional purpose, or raise a complaint with the competent Egyptian authority.',
        ],
        paragraphsAr: [
          'نحتفظ بسجلات الطلبات والفواتير للمدد التي يفرضها القانون. ونحتفظ بباقي البيانات ما دامت لازمة للغرض الموضح أو لمعالجة طلب أو نزاع.',
          'يمكنك التواصل مع البائع عبر البيانات بهذه الصفحة للاستفسار عن بياناتك الشخصية أو طلب تصحيحها أو طلب حذف البيانات التي لا يلزم الاحتفاظ بها قانونًا. وقد نحتاج إلى التحقق من هويتك قبل تنفيذ الطلب.',
          'ويمكنك كذلك الاستفسار عن حق الاطلاع أو التقييد أو الاعتراض حيث ينطبق، وسحب موافقة سبق تقديمها لغرض اختياري، أو تقديم شكوى إلى الجهة المصرية المختصة.',
        ],
      },
      {
        heading: 'Messages and marketing',
        headingAr: 'رسائل الطلب والتسويق',
        paragraphs: [
          'We may contact you with service messages about an order or account. Promotional messages require a separate, optional choice when that service is offered. You can withdraw a marketing choice through the channel used for the message.',
        ],
        paragraphsAr: [
          'قد نرسل إليك رسائل خدمية تخص الطلب أو الحساب. أما الرسائل الترويجية فتتطلب اختيارًا منفصلًا واختياريًا عند توفير هذه الخدمة، ويمكنك سحب موافقتك التسويقية عبر القناة التي وصلتك الرسالة من خلالها.',
        ],
      },
    ],
  },
  {
    id: 'returns',
    slug: 'returns-refunds-exchanges',
    title: 'Returns, Refunds & Exchanges',
    titleAr: 'الاسترجاع والاسترداد والاستبدال',
    summary: 'Choose the right path for a change of mind, a defect, or a delivery issue.',
    summaryAr: 'اعرف الإجراء المناسب عند العدول عن الشراء أو وجود عيب أو مشكلة في التوصيل.',
    sections: [
      {
        heading: 'Change of mind',
        headingAr: 'العدول عن الشراء',
        paragraphs: [
          'For a distance purchase, you may withdraw from the contract within 14 days after receiving the item, subject to the statutory exceptions. For goods, the refund is made by the original payment method within seven days after the item is returned, unless another method is agreed. Under Article 40, the consumer bears shipping and return costs unless the contract provides otherwise. If you ask to exchange an item, support will confirm availability; this does not limit your statutory withdrawal rights.',
          'Article 41 exceptions include a service fully used before the withdrawal period ends; goods made to the consumer’s request or specifications; opened video, CD, software, or printed-media packaging; a defect caused by the consumer’s improper handling; and cases where withdrawal conflicts with the product’s nature or commercial custom, or is abusive, as specified by the law and its regulations.',
          'To start a request, contact customer support with your order number and the item you wish to return or exchange. Keep the item and its accessories available while support explains the next step.',
        ],
        paragraphsAr: [
          'في الشراء عن بُعد، يجوز للمستهلك الرجوع عن التعاقد خلال 14 يومًا من تسلم السلعة، مع مراعاة الاستثناءات القانونية. وبالنسبة للسلع، يُرد المبلغ بطريقة الدفع نفسها خلال مدة لا تجاوز سبعة أيام من إعادة السلعة، ما لم يُتفق على طريقة أخرى. ووفق المادة 40، يتحمل المستهلك مصاريف الشحن وإعادة السلعة ما لم ينص العقد على غير ذلك. إذا طلبت الاستبدال، يؤكد لك الدعم توافره، مع بقاء حقك القانوني في الرجوع قائمًا.',
          'تشمل استثناءات المادة 41: الاستفادة الكاملة من الخدمة قبل انتهاء مهلة الرجوع؛ والسلع المصنوعة بناءً على طلب المستهلك أو وفق مواصفاته؛ والوسائط المرئية أو الأقراص أو البرامج أو المطبوعات التي أزيل غلافها؛ والعيب الناتج عن سوء حيازة المستهلك؛ والحالات التي يتعارض فيها طلب الرجوع مع طبيعة المنتج أو العرف التجاري أو يشكل تعسفًا، وفق ما يحدده القانون ولائحته.',
          'لبدء الطلب، تواصل مع خدمة العملاء برقم الطلب والمنتج المطلوب إرجاعه أو استبداله. واحتفظ بالمنتج وملحقاته حتى يوضح لك الدعم الخطوة التالية.',
        ],
      },
      {
        heading: 'Defective or non-conforming items',
        headingAr: 'المنتج المعيب أو غير المطابق',
        paragraphs: [
          'If an item is defective or does not match its description, contact support within 30 days of receiving it and explain the issue. Consumer law provides remedies for defective goods, including replacement or refund as applicable, without additional cost to the consumer.',
          'A made-to-order item may be excluded from change-of-mind returns in the circumstances set by law. This does not remove rights that apply when the item is defective or non-conforming.',
        ],
        paragraphsAr: [
          'إذا كان المنتج معيبًا أو غير مطابق لوصفه، تواصل مع الدعم خلال 30 يومًا من تسلمه واشرح المشكلة. ويقرر قانون حماية المستهلك حقوقًا للسلع المعيبة، تشمل الاستبدال أو رد القيمة بحسب الحالة، دون تكلفة إضافية على المستهلك.',
          'قد تُستثنى السلعة المصنوعة وفق مواصفات خاصة من العدول عن الشراء في الحالات التي يحددها القانون. ولا يسقط ذلك الحقوق المتعلقة بوجود عيب أو عدم مطابقة.',
        ],
      },
      {
        heading: 'Refund timing and delivery delays',
        headingAr: 'موعد الاسترداد وتأخر التوصيل',
        paragraphs: [
          'For a distance-sale withdrawal, the refund must be made by the original payment method within seven days after the item is returned, unless another method is agreed. For a defective item, the Consumer Protection Agency’s published guidance states that the supplier must replace the item or refund its price without additional cost within one week after the consumer approaches the supplier.',
          'If delivery is later than the agreed date, or the item has not been delivered within 30 days when no date was agreed, you may withdraw without expense within 14 days from the date of delay or the date of receipt, whichever is later, by notifying the supplier. The supplier bears delivery and return costs and refunds you as required by law.',
          'Support will confirm the return route, any applicable collection arrangement, and the refund status using the contact details linked to your order.',
        ],
        paragraphsAr: [
          'في الرجوع عن شراء سلعة عن بُعد، يُرد المبلغ بطريقة الدفع نفسها خلال مدة لا تجاوز سبعة أيام من إعادة السلعة، ما لم يُتفق على طريقة أخرى. وفي حالة السلعة المعيبة، يوضح الإرشاد المنشور لجهاز حماية المستهلك وجوب استبدالها أو رد قيمتها دون تكلفة إضافية خلال أسبوع من لجوء المستهلك إلى المورد.',
          'إذا تأخر التسليم عن الموعد المتفق عليه، أو لم تُسلّم السلعة خلال 30 يومًا عند عدم الاتفاق على موعد، يجوز لك الرجوع دون نفقات خلال 14 يومًا من تاريخ التأخر أو تاريخ الاستلام، أيهما أطول، بعد إخطار المورد. ويتحمل المورد مصاريف التسليم وإعادة الشحن ويرد المبلغ وفق القانون.',
          'يوضح لك الدعم طريقة الإرجاع وترتيب الاستلام إن وجد وحالة الاسترداد عبر بيانات الاتصال المرتبطة بطلبك.',
        ],
      },
    ],
  },
  {
    id: 'shipping',
    slug: 'shipping-delivery',
    title: 'Shipping & Delivery',
    titleAr: 'الشحن والتوصيل',
    summary: 'Delivery coverage, charges, and estimated timing are shown before you order.',
    summaryAr: 'تظهر مناطق التوصيل والرسوم والمدة المتوقعة قبل تأكيد الطلب.',
    sections: [
      {
        heading: 'Coverage, cost, and timing',
        headingAr: 'التغطية والتكلفة والمدة',
        paragraphs: [
          'Available delivery options depend on the address you enter. The checkout calculates the delivery charge and shows the estimated delivery window before you confirm the order. That information is saved with the order.',
          'If we miss the delivery date shown in checkout, or the item has not been delivered within 30 days when no date was agreed, you may withdraw without expense within 14 days from the delay date or the receipt date, whichever is later, after notifying the supplier. The supplier bears delivery and return costs and refunds you under Egyptian law.',
        ],
        paragraphsAr: [
          'تعتمد خيارات التوصيل المتاحة على العنوان الذي تدخله. تحسب صفحة الشراء رسوم التوصيل وتعرض المدة المتوقعة قبل التأكيد، وتُحفظ هذه البيانات مع الطلب.',
          'إذا تجاوز التسليم الموعد الظاهر في صفحة الشراء، أو لم تُسلّم السلعة خلال 30 يومًا عند عدم الاتفاق على موعد، يجوز لك الرجوع دون نفقات خلال 14 يومًا من تاريخ التأخر أو تاريخ الاستلام، أيهما أطول، بعد إخطار المورد. ويتحمل المورد مصاريف التسليم وإعادة الشحن ويرد المبلغ وفق قانون حماية المستهلك المصري.',
        ],
      },
      {
        heading: 'Receiving your order',
        headingAr: 'استلام الطلب',
        paragraphs: [
          'Provide a complete address and a phone number where the recipient can be reached. The carrier may contact the recipient to coordinate delivery. If an address needs to change after the order is placed, contact support as soon as possible. A change may affect the delivery estimate or charge.',
          'If a parcel arrives damaged or an item is missing, contact customer support with your order number and a description of the issue so we can review it with the carrier.',
        ],
        paragraphsAr: [
          'أدخل عنوانًا كاملًا ورقم هاتف يمكن الوصول من خلاله إلى المستلم. وقد يتواصل مندوب الشحن لتنسيق التسليم. إذا احتجت إلى تغيير العنوان بعد الطلب، تواصل مع الدعم بأسرع وقت، فقد يتغير موعد التوصيل أو تكلفته.',
          'إذا وصل الطرد تالفًا أو كان أحد المنتجات ناقصًا، أرسل إلى خدمة العملاء رقم الطلب ووصف المشكلة لمراجعتها مع شركة الشحن.',
        ],
      },
      {
        heading: 'Delivery attempts and returns to sender',
        headingAr: 'محاولات التسليم وإعادة الشحنة',
        paragraphs: [
          'If the carrier cannot reach the recipient or access the address, it may arrange another delivery attempt according to its operating process. Contact support if tracking stops updating or a delivery attempt fails.',
        ],
        paragraphsAr: [
          'إذا تعذر الوصول إلى المستلم أو العنوان، قد تنسق شركة الشحن محاولة أخرى وفق إجراءاتها التشغيلية. تواصل مع الدعم إذا توقف تحديث التتبع أو فشلت محاولة التسليم.',
        ],
      },
    ],
  },
  {
    id: 'payments',
    slug: 'payment-billing',
    title: 'Payment & Billing',
    titleAr: 'الدفع والفواتير',
    summary: 'See the total and available payment methods before confirming your order.',
    summaryAr: 'راجع الإجمالي ووسائل الدفع المتاحة قبل تأكيد الطلب.',
    sections: [
      {
        heading: 'Prices and charges',
        headingAr: 'الأسعار والرسوم',
        paragraphs: [
          'Prices and charges are shown in Egyptian pounds. Before you confirm, checkout displays the items, discounts, delivery charge, any applicable payment fee or tax, and the final total. If the total changes before order submission, review the updated amount before continuing.',
          'The payment options shown for your order depend on the store’s active payment configuration.',
        ],
        paragraphsAr: [
          'تُعرض الأسعار والرسوم بالجنيه المصري. قبل التأكيد، تعرض صفحة الشراء المنتجات والخصومات ورسوم التوصيل وأي رسوم دفع أو ضريبة منطبقة والإجمالي النهائي. إذا تغير الإجمالي قبل إرسال الطلب، راجع المبلغ الجديد قبل المتابعة.',
          'تعتمد وسائل الدفع المعروضة لطلبك على الإعدادات النشطة للمتجر.',
        ],
      },
      {
        heading: 'Payment status and refunds',
        headingAr: 'حالة الدفع والاسترداد',
        paragraphs: [
          'A payment may remain pending while the payment provider or store confirms its status. If you see a duplicate charge or a failed payment that appears on your statement, contact support and share the order number and payment reference.',
          'Approved refunds are returned according to the applicable return policy and payment method. Cash-on-delivery refunds are coordinated by customer support using the contact details on the order.',
        ],
        paragraphsAr: [
          'قد تظل حالة الدفع معلقة إلى أن يؤكدها مزود الدفع أو المتجر. إذا ظهر خصم مكرر أو ظهر دفع فاشل في كشف حسابك، تواصل مع الدعم وأرسل رقم الطلب ومرجع الدفع.',
          'تُرد المبالغ الموافق عليها وفق سياسة الاسترجاع وطريقة الدفع المنطبقة. وينسق فريق الدعم رد مبالغ الدفع عند الاستلام عبر بيانات الاتصال المسجلة في الطلب.',
        ],
      },
      {
        heading: 'Invoices and receipts',
        headingAr: 'الفواتير والإيصالات',
        paragraphs: [
          'A sales invoice or receipt is issued for the completed purchase according to the seller’s applicable tax and electronic-receipt obligations. Contact support if you need a copy or notice an error in the document.',
        ],
        paragraphsAr: [
          'تصدر فاتورة أو إيصال البيع عن الشراء وفق الالتزامات الضريبية والتزامات الإيصال الإلكتروني المنطبقة على البائع. تواصل مع الدعم إذا احتجت نسخة أو لاحظت خطأ في المستند.',
        ],
      },
    ],
  },
  {
    id: 'cookies',
    slug: 'cookies',
    title: 'Cookie & Browser Storage Policy',
    titleAr: 'سياسة ملفات تعريف الارتباط وتخزين المتصفح',
    summary: 'The store uses browser storage to remember choices and keep shopping working.',
    summaryAr: 'يستخدم المتجر مساحة تخزين المتصفح لتذكر اختياراتك وتشغيل تجربة التسوق.',
    sections: [
      {
        heading: 'What the store uses',
        headingAr: 'ما يستخدمه المتجر',
        paragraphs: [
          'The storefront uses local browser storage for essential functions. It saves the language choice in varya_store_lang, connects a guest basket with cart_session, and preserves an unfinished checkout attempt in pending_checkout with a 15-minute retry window. A short order receipt is kept in the current browser session for 30 minutes after checkout.',
          'An unfinished checkout entry can contain the basket, total, payment selection, and contact and delivery details entered for that order. Its retry window expires after 15 minutes; the expired entry is deleted when checkout next checks the saved attempt, or earlier if you clear site data. The expired value may remain in browser storage until that cleanup happens.',
          'The temporary order receipt can include the order number, item and charge summary, payment status, accepted policy version and time, and the recipient phone number for a guest order. For bank transfer orders it may also hold the transfer instructions displayed with the receipt.',
          'These entries are stored by your browser and can be removed through its site-data controls. Removing them may disconnect a guest basket, clear a saved language choice, or remove an unfinished checkout attempt.',
        ],
        paragraphsAr: [
          'يستخدم المتجر مساحة التخزين المحلية في المتصفح لوظائف أساسية. تُحفظ اللغة في varya_store_lang، وتُربط سلة الزائر باستخدام cart_session، وتُحفظ محاولة الشراء غير المكتملة في pending_checkout بنافذة لإعادة المحاولة مدتها 15 دقيقة. كما يُحفظ ملخص قصير للطلب في جلسة المتصفح الحالية لمدة 30 دقيقة بعد الشراء.',
          'قد تتضمن محاولة الشراء غير المكتملة السلة والإجمالي وطريقة الدفع وبيانات الاتصال والتوصيل التي أدخلتها للطلب. تنتهي صلاحية المحاولة بعد 15 دقيقة، ويحذف المتجر سجلها المنتهي عند فحص المحاولة المحفوظة في زيارة الشراء التالية، أو يمكنك حذفه قبل ذلك بمسح بيانات الموقع. وقد يظل السجل المنتهي موجودًا في تخزين المتصفح إلى أن يحدث هذا التنظيف.',
          'قد يتضمن ملخص الطلب المؤقت رقم الطلب وملخص المنتجات والرسوم وحالة الدفع وإصدار السياسات ووقت الموافقة ورقم هاتف مستلم الطلب عند الشراء كضيف. وقد يتضمن أيضًا تعليمات التحويل التي تظهر مع الإيصال في طلبات التحويل البنكي.',
          'يحفظ المتصفح هذه البيانات ويمكنك حذفها من إعدادات بيانات المواقع لديه. وقد يؤدي حذفها إلى فصل سلة الزائر أو مسح اختيار اللغة أو حذف محاولة شراء غير مكتملة.',
        ],
      },
      {
        heading: 'Your choices',
        headingAr: 'اختياراتك',
        paragraphs: [
          'You can block or remove browser storage through your browser settings. Some store functions may not work as expected if essential storage is blocked. If optional analytics or advertising technologies are introduced, the store will update this policy and provide the applicable choice controls before using them.',
        ],
        paragraphsAr: [
          'يمكنك حظر مساحة تخزين المتصفح أو حذفها من إعداداته. وقد لا تعمل بعض وظائف المتجر كما ينبغي عند حظر التخزين الضروري. وإذا أضيفت تقنيات اختيارية للتحليل أو الإعلان، فسيُحدّث المتجر هذه السياسة ويوفر خيارات التحكم المناسبة قبل استخدامها.',
        ],
      },
    ],
  },
];

/**
 * Published policy copies are immutable by version. Add a new entry for any
 * future change instead of editing an existing published entry.
 */
export const LEGAL_POLICY_VERSIONS: Readonly<Record<string, readonly LegalPolicy[]>> = Object.freeze({
  '2026-11-20-v1': Object.freeze(legalPoliciesV1),
});

const currentPolicies = LEGAL_POLICY_VERSIONS[LEGAL_POLICY_VERSION];
if (!currentPolicies) {
  throw new Error(`Missing archived legal policy text for version ${LEGAL_POLICY_VERSION}`);
}
export const LEGAL_POLICIES = currentPolicies;

export function getLegalPoliciesForVersion(version: string): readonly LegalPolicy[] | undefined {
  return LEGAL_POLICY_VERSIONS[version];
}

export function getLegalPolicyBySlug(slug: string, version = LEGAL_POLICY_VERSION): LegalPolicy | undefined {
  return getLegalPoliciesForVersion(version)?.find((policy) => policy.slug === slug);
}

export function getLegalPolicyById(id: LegalPolicyId, version = LEGAL_POLICY_VERSION): LegalPolicy | undefined {
  return getLegalPoliciesForVersion(version)?.find((policy) => policy.id === id);
}
