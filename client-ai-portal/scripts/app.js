/**
 * Alfarid VARYA Enterprise AI Governance & Capabilities Portal
 * Apple Pro Design System & Anti-Slop Swiss Minimalism
 * Interactive Sandbox Engine: What the user asks & What happens behind the scenes
 */

// Global State for the 10 Departments
const domainStates = {
  catalog: { isLocked: false, isExecuted: false, isCommitted: false },
  inventory: { isLocked: false, isExecuted: false, isCommitted: false },
  pricing: { isLocked: false, isExecuted: false, isCommitted: false },
  finance: { isLocked: false, isExecuted: false, isCommitted: false },
  wholesale: { isLocked: false, isExecuted: false, isCommitted: false },
  shipping: { isLocked: false, isExecuted: false, isCommitted: false },
  customers: { isLocked: false, isExecuted: false, isCommitted: false },
  storefront: { isLocked: false, isExecuted: false, isCommitted: false },
  admin: { isLocked: false, isExecuted: false, isCommitted: false },
  settings: { isLocked: false, isExecuted: false, isCommitted: false }
};

// Original HTML Cache for Resilient Resets
const initialTicketTemplates = {};

document.addEventListener("DOMContentLoaded", () => {
  cacheInitialTemplates();
  initTheme();
  initStickyDockScrollSpy();
  initSimulationHandlers();
  initCatalogScenarioSwitcher();
  initKillSwitchHandlers();
  initMasterLockdown();
  initSIEMDrawer();
  initPrintButton();
});

/**
 * Cache original tickets so users can reset and retry anytime
 */
function cacheInitialTemplates() {
  Object.keys(domainStates).forEach((deptId) => {
    const ticket = document.getElementById(`proposal-ticket-${deptId}`);
    if (ticket) {
      initialTicketTemplates[deptId] = ticket.innerHTML;
    }
  });
}

/**
 * Theme Manager (Apple Dark / Light)
 */
function initTheme() {
  const saved = localStorage.getItem("alfarid_theme") || "dark";
  document.documentElement.setAttribute("data-theme", saved);
  updateThemeIcon(saved);

  const btn = document.getElementById("theme-toggle-btn");
  if (btn) {
    btn.addEventListener("click", () => {
      const cur = document.documentElement.getAttribute("data-theme") || "dark";
      const nxt = cur === "dark" ? "light" : "dark";
      document.documentElement.setAttribute("data-theme", nxt);
      localStorage.setItem("alfarid_theme", nxt);
      updateThemeIcon(nxt);
    });
  }
}

function updateThemeIcon(theme) {
  const btn = document.getElementById("theme-toggle-btn");
  if (!btn) return;
  if (theme === "light") {
    btn.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>`;
    btn.title = "تبديل إلى الوضع الداكن (Dark)";
  } else {
    btn.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>`;
    btn.title = "تبديل إلى الوضع الفاتح (Light)";
  }
}

/**
 * Sticky Dock ScrollSpy (Apple-grade floating jump dock)
 */
function initStickyDockScrollSpy() {
  const dockBtns = document.querySelectorAll(".dock-jump-btn");
  const sections = document.querySelectorAll(".department-full-card");

  dockBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      const targetId = btn.getAttribute("data-target");
      const targetEl = document.getElementById(targetId);
      if (targetEl) {
        targetEl.scrollIntoView({ behavior: "smooth" });
      }
    });
  });

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const id = entry.target.id;
        dockBtns.forEach((btn) => {
          if (btn.getAttribute("data-target") === id) {
            btn.classList.add("active");
          } else {
            btn.classList.remove("active");
          }
        });
      }
    });
  }, { rootMargin: "-20% 0px -70% 0px" });

  sections.forEach((sec) => observer.observe(sec));
}

/**
 * Interactive Simulation Handlers for ALL 10 Departments
 */
function initSimulationHandlers() {
  const runButtons = document.querySelectorAll(".run-simulation-btn");
  runButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const deptId = btn.getAttribute("data-dept");
      runDepartmentSimulation(deptId);
    });
  });

  bindTicketButtons();
}

/**
 * Binds Confirm and Abort action buttons within Proposal Tickets
 */
function bindTicketButtons() {
  document.querySelectorAll(".ticket-confirm-btn").forEach((btn) => {
    btn.onclick = () => {
      const deptId = btn.getAttribute("data-dept");
      confirmDepartmentProposal(deptId);
    };
  });

  document.querySelectorAll(".ticket-abort-btn").forEach((btn) => {
    btn.onclick = () => {
      const deptId = btn.getAttribute("data-dept");
      abortDepartmentProposal(deptId);
    };
  });
}

/**
 * Runs the live 3-phase reaction simulation for a department
 */
function runDepartmentSimulation(deptId) {
  const state = domainStates[deptId];
  const reactionBox = document.getElementById(`reaction-box-${deptId}`);
  if (!reactionBox) return;

  // Check if locked
  if (state.isLocked) {
    // Show non-destructive access denied banner
    let deniedBanner = reactionBox.querySelector(".denied-alert-banner");
    if (!deniedBanner) {
      deniedBanner = document.createElement("div");
      deniedBanner.className = "denied-alert-banner";
      reactionBox.insertBefore(deniedBanner, reactionBox.firstChild);
    }
    deniedBanner.style.display = "block";
    deniedBanner.innerHTML = `
      <div style="padding: 1rem 1.25rem; background: var(--state-lock-bg); border: 1px solid var(--state-lock-border); border-radius: var(--radius-sm); color: var(--state-lock-text); font-size: 0.85rem; line-height: 1.5; margin-bottom: 1rem; animation: shake 0.4s ease-in-out;">
        <div style="font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
          <span>تم حظر العملية فورياً (403 Forbidden - Access Revoked via Kill-Switch)</span>
        </div>
        <div>الصلاحيات مقيدة حالياً لهذا القسم. تم إبطال توكن Sanctum في أقل من 24ms وعزل مقبس التنفيذ الحي. لن يُرسل أي استعلام ولن تُسجل أي حركة بقاعدة البيانات.</div>
      </div>
    `;

    logSiemEvent({
      status: "DENIED_LOCKED (403)",
      color: "var(--state-lock)",
      actor: `merchant_${deptId}`,
      action: `attempted_query_${deptId}`,
      details: `SECURITY: Rejected at API Gateway. Token Revoked. Latency: 22ms.`
    });
    return;
  }

  // Remove any previous denied banner if present
  const deniedBanner = reactionBox.querySelector(".denied-alert-banner");
  if (deniedBanner) deniedBanner.style.display = "none";

  // Reset ticket if it was previously confirmed/aborted
  resetDepartmentProposalTicket(deptId);

  // Set running state
  reactionBox.style.display = "flex";
  reactionBox.scrollIntoView({ behavior: "smooth", block: "nearest" });

  const phaseSteps = reactionBox.querySelectorAll(".phase-step");
  phaseSteps.forEach((s) => {
    s.style.opacity = "0.35";
    s.style.borderColor = "var(--border-hairline)";
  });

  // Animate Step 1: Verification & RBAC
  setTimeout(() => {
    if (phaseSteps[0]) {
      phaseSteps[0].style.opacity = "1";
      phaseSteps[0].style.borderColor = "var(--state-active-border)";
      phaseSteps[0].style.backgroundColor = "var(--state-active-bg)";
    }
  }, 250);

  // Animate Step 2: Concurrency Lock & Calculations
  setTimeout(() => {
    if (phaseSteps[1]) {
      phaseSteps[1].style.opacity = "1";
      phaseSteps[1].style.borderColor = "var(--state-active-border)";
      phaseSteps[1].style.backgroundColor = "var(--state-active-bg)";
    }
  }, 650);

  // Animate Step 3: Two-Phase Commit Proposal Ready
  setTimeout(() => {
    if (phaseSteps[2]) {
      phaseSteps[2].style.opacity = "1";
      phaseSteps[2].style.borderColor = "var(--state-active-border)";
      phaseSteps[2].style.backgroundColor = "var(--state-active-bg)";
    }
    const proposalTicket = document.getElementById(`proposal-ticket-${deptId}`);
    if (proposalTicket) {
      proposalTicket.style.display = "block";
      proposalTicket.style.animation = "fadeSlideIn 0.35s ease-out";
    }

    logSiemEvent({
      status: "PROPOSAL_STAGED (202)",
      color: "var(--state-warn)",
      actor: `ai_assistant_${deptId}`,
      action: `stage_pending_action_${deptId}`,
      details: `ULID: act_${deptId}_${Date.now().toString(36)} | 2-Phase Lock Active | Awaiting Human Decision`
    });
  }, 1000);
}

/**
 * Resets proposal ticket back to the original proposal HTML
 */
function resetDepartmentProposalTicket(deptId) {
  const proposalTicket = document.getElementById(`proposal-ticket-${deptId}`);
  if (proposalTicket) {
    if (deptId === "catalog" && catalogCategoryScenarios[currentCatalogScenarioKey]) {
      proposalTicket.innerHTML = catalogCategoryScenarios[currentCatalogScenarioKey].ticket;
    } else if (initialTicketTemplates[deptId]) {
      proposalTicket.innerHTML = initialTicketTemplates[deptId];
    }
    bindTicketButtons();
  }
}

/**
 * Catalog & Category Taxonomy Scenarios
 */
const catalogCategoryScenarios = {
  "cat_create": {
    label: "إنشاء قسم فرعي جديد",
    prompt: `"أنشئ قسم فرعي جديد باسم 'شنط كروس جلد طبيعي' (Crossbody Leather Bags) تحت قسم 'شنط حريمي' واقترح الـ slug تلقائياً ورتبه في المركز الثالث."`,
    phases: [
      {
        title: "1. التدقيق والتحقق من العمق (Depth Guard)",
        desc: "فحص صلاحية <code>categories.create</code> والتحقق من أن عمق القسم لن يتجاوز الحد الأقصى المسموح به (3 مستويات كحد أقصى في الشجرة)."
      },
      {
        title: "2. فحص حالة الأب وتوليد الـ Slug",
        desc: "التحقق من أن قسم 'شنط حريمي' نشط، وتوليد slug فريد <code>crossbody-leather-bags</code> مع دعم تطبيع الحروف العربية."
      },
      {
        title: "3. مقترح ثنائي الطور ومعاينة الشجرة",
        desc: "حجز قفل <code>CatalogLock::tree</code> وتجهيز مسودة مقترح <code>AiPendingAction</code> مع شجرة الفروقات category_tree_diff."
      }
    ],
    ticket: `
      <div class="ticket-header">
        <span>PROPOSAL ID: act_cat_create_109</span>
        <span>SHA-256: 8f4a1c2298fc1c149afbf4c8...</span>
      </div>
      <div class="ticket-body-content">
        <strong>المقترح الجاهز للاعتماد (Create Category):</strong> إنشاء قسم فرعي "شنط كروس جلد طبيعي" تحت القسم الأب "شنط حريمي".<br>
        • الـ Slug المولد: <code>crossbody-leather-bags</code> | ترتيب العرض: 3 | عمق الشجرة: المستوى 2 (مقبول).<br>
        • حماية: يدعم التراجع الفوري (Undo) واستعادة الحالة السابقة خلال 15 دقيقة.
      </div>
      <div class="tree-diff-box">
        <span class="node-root">📁 شنط حريمي (Parent Active)</span><br>
        &nbsp;&nbsp;├── 📁 شنط يد كلاسيك<br>
        &nbsp;&nbsp;└── <span class="node-new">✨ [جديد] 📁 شنط كروس جلد طبيعي (sort_order: 3)</span>
      </div>
      <div class="ticket-actions-bar">
        <span style="font-size: 0.75rem; color: var(--label-tertiary);">الضابط البشري: لن يُدرج القسم في المتجر إلا بعد اعتمادك:</span>
        <div style="display: flex; gap: 0.5rem;">
          <button class="apple-btn apple-btn-danger ticket-abort-btn" data-dept="catalog" type="button">إلغاء المقترح</button>
          <button class="apple-btn apple-btn-restore ticket-confirm-btn" data-dept="catalog" type="button">اعتماد وإنشاء القسم</button>
        </div>
      </div>
    `
  },
  "cat_move": {
    label: "نقل قسم في الشجرة والترتيب",
    prompt: `"انقل قسم 'محافظ حريمي' ليكون تحت قسم 'شنط حريمي' بدلاً من وجوده في الجذر، واضبط ترتيبه ليكون الترتيب الثاني وفحص مسار النشاط."`,
    phases: [
      {
        title: "1. فحص الحلقات والتكرار (Loop Guard)",
        desc: "استدعاء <code>MoveCategoryTool</code> وفحص شجرة الأنساب لضمان عدم نقل قسم تحت أحد فروعه والتحقق من عمق الشجرة (<= 3)."
      },
      {
        title: "2. فحص مسارات النشاط للمنتجات (active_path)",
        desc: "التأكد من أن القسم الأب الجديد 'شنط حريمي' نشط حتى لا تصبح منتجات القسم المنقول معزولة عن المتجر."
      },
      {
        title: "3. حجز قفل الشجرة ومعاينة الفروقات",
        desc: "حجز <code>CatalogLock::tree</code> الحصري وإعداد معاينة الفروقات قبل وبعد <code>category_tree_diff</code> مع حماية التعارض lock_version."
      }
    ],
    ticket: `
      <div class="ticket-header">
        <span>PROPOSAL ID: act_cat_move_214</span>
        <span>SHA-256: 4b7c9e1122aa1c149afbf77a...</span>
      </div>
      <div class="ticket-body-content">
        <strong>المقترح الجاهز للاعتماد (Move Category):</strong> نقل قسم "محافظ حريمي" من الجذر إلى تحت "شنط حريمي" (sort_order: 2).<br>
        • فحص الحلقات: لا توجد حلقات دائرية (No Cycles). العمق الناتج: المستوى 2.<br>
        • مسار النشاط (active_path): معتمد ونشط بالكامل. يدعم التراجع (Undo).
      </div>
      <div class="tree-diff-box">
        <span class="node-old"><s>[Root] 📁 محافظ حريمي</s></span> (نُقل)<br>
        <span class="node-root">📁 شنط حريمي</span><br>
        &nbsp;&nbsp;├── 📁 شنط يد كلاسيك (sort_order: 1)<br>
        &nbsp;&nbsp;└── <span class="node-new">↳ 📁 محافظ حريمي (sort_order: 2) [تم نقله هنا]</span>
      </div>
      <div class="ticket-actions-bar">
        <span style="font-size: 0.75rem; color: var(--label-tertiary);">الضابط البشري: لا تتم إعادة هيكلة الشجرة إلا بعد موافقتك:</span>
        <div style="display: flex; gap: 0.5rem;">
          <button class="apple-btn apple-btn-danger ticket-abort-btn" data-dept="catalog" type="button">إلغاء المقترح</button>
          <button class="apple-btn apple-btn-restore ticket-confirm-btn" data-dept="catalog" type="button">اعتماد نقل القسم</button>
        </div>
      </div>
    `
  },
  "cat_health": {
    label: "كشف صحة الأقسام وإصلاح الفاضية",
    prompt: `"افحص شجرة الكتالوج بالكامل واكشف لي الأقسام الفاضية أو المعطلة تحت آباء نشطة واقترح خطة إصلاح لتعطيلها."`,
    phases: [
      {
        title: "1. مسح الكتالوج الشامل (CatalogHealthReportTool)",
        desc: "فحص كافة فروع الشجرة واكتشاف الأقسام الخالية من المنتجات والفروع (empty_categories) والأقسام النشطة تحت آباء معطلة."
      },
      {
        title: "2. خطة الإصلاح المقترحة (PlanCatalogFixesTool)",
        desc: "تجهيز خطة إصلاح تتضمن تعطيل قسمين فارغين عبر <code>set_category_status</code> دون حذفهما لحفظ الأرشيف المحاسبي."
      },
      {
        title: "3. توثيق المراجعة (StaffAdministration::audit)",
        desc: "تسجيل نتائج الفحص في سجل التدقيق الأمني مع تجزئة SHA-256 للمسودة."
      }
    ],
    ticket: `
      <div class="ticket-header">
        <span>PROPOSAL ID: act_cat_health_308</span>
        <span>SHA-256: 1d2e3f445566778899aabbcc...</span>
      </div>
      <div class="ticket-body-content">
        <strong>تقرير صحة الأقسام وخطة الإصلاح (Health & Fix Plan):</strong> تم فحص 28 قسماً في الكتالوج:<br>
        • <span class="node-warn">⚠️ رصد 2 قسم فارغ (empty_categories):</span> "أحزمة جلد كلاسيك" و "شنط ظهر مدرسية قديمة".<br>
        • المقترح: تعطيل القسمين فورياً لحماية تجربة العميل بالمتجر. يدعم التراجع (Undo).
      </div>
      <div class="tree-diff-box">
        <span class="node-warn">⚠️ خطة الإصلاح المقترحة (PlanCatalogFixes):</span><br>
        - تعطيل: 📁 أحزمة جلد كلاسيك (Status: active → inactive) [بدون منتجات]<br>
        - تعطيل: 📁 شنط ظهر مدرسية قديمة (Status: active → inactive) [بدون منتجات]
      </div>
      <div class="ticket-actions-bar">
        <span style="font-size: 0.75rem; color: var(--label-tertiary);">الضابط البشري: تطبيق خطة الإصلاح يتطلب موافقتك الصريحة:</span>
        <div style="display: flex; gap: 0.5rem;">
          <button class="apple-btn apple-btn-danger ticket-abort-btn" data-dept="catalog" type="button">إلغاء الخطة</button>
          <button class="apple-btn apple-btn-restore ticket-confirm-btn" data-dept="catalog" type="button">اعتماد تنفيذ الإصلاحات</button>
        </div>
      </div>
    `
  },
  "cat_guards": {
    label: "صمامات التعطيل والحذف المشدد",
    prompt: `"عايز أحذف قسم 'شنط سفر تجريبية' وعطّل قسم 'شنط يد شبابية'."`,
    phases: [
      {
        title: "1. فحص المنتجات المنشورة (Status Guard)",
        desc: "استدعاء <code>SetCategoryStatusTool</code> ورفض تعطيل 'شنط يد شبابية' لوجود 12 منتجاً منشوراً تحته، وعرض عينة منها أولاً."
      },
      {
        title: "2. فحص خلو القسم للحذف (DeleteCategoryTool)",
        desc: "التحقق من أن قسم 'شنط سفر تجريبية' فارغ تماماً (بدون منتجات وبدون فروع). الحذف مرفوض لو تحته أي سجل."
      },
      {
        title: "3. تصنيف عالي الخطورة وتأكيد الـ Slug",
        desc: "تصنيف الأداة عالي الخطورة (high risk) مع اشتراط كتابة الـ slug يدوياً للتأكيد. عملية الحذف نهائية وبلا تراجع."
      }
    ],
    ticket: `
      <div class="ticket-header">
        <span>PROPOSAL ID: act_cat_guard_402</span>
        <span>SHA-256: 99887766554433221100aabb...</span>
      </div>
      <div class="ticket-body-content">
        <strong>حماية وصمامات الأمان المشددة (Category Safeguards):</strong><br>
        • <span style="color: var(--state-lock); font-weight: 700;">❌ تم رفض تعطيل "شنط يد شبابية":</span> يوجد 12 منتجاً منشوراً نشطاً تحته (مثل: شنطة يد كاجوال #204). يجب نقل المنتجات أو أرشفتها أولاً.<br>
        • <span style="color: var(--state-active); font-weight: 700;">✅ قسم "شنط سفر تجريبية" فارغ تماماً:</span> جاهز للحذف النهائي عبر <code>DeleteCategoryTool</code>.<br>
        • ⚠️ <strong>إجراء تأكيدي عالي الخطورة:</strong> لتنفيذ الحذف، يجب كتابة الـ Slug <code>trial-travel-bags</code> للتأكيد (حذف نهائي بلا تراجع).
      </div>
      <div class="ticket-actions-bar">
        <span style="font-size: 0.75rem; color: var(--label-tertiary);">الضابط البشري: تأكيد الحذف عالي الخطورة:</span>
        <div style="display: flex; gap: 0.5rem;">
          <button class="apple-btn apple-btn-danger ticket-abort-btn" data-dept="catalog" type="button">إلغاء العملية</button>
          <button class="apple-btn apple-btn-restore ticket-confirm-btn" data-dept="catalog" type="button">تأكيد حذف القسم الفارغ</button>
        </div>
      </div>
    `
  },
  "product_draft": {
    label: "إضافة منتج ومسودة باركود (المنتجات)",
    prompt: `"عايز أضيف موديل شنطة كروس جلد طبيعي باسم 'شنطة أكسفورد ريترو' بسعر قطاعي 650 ج.م وسعر جملة 480 ج.م بـ 4 ألوان (أسود، هافان، بني، كحلي) وأولد باركود دولي EAN-13 لكل لون."`,
    phases: [
      {
        title: "1. التدقيق والتحقق",
        desc: "فحص توكن <code>catalog:write_drafts</code> والتحقق من عدم تكرار الاسم في الكتالوج عبر Trigram Search."
      },
      {
        title: "2. التجهيز والباركود",
        desc: "إنشاء 4 متغيرات للألوان وتوليد 4 أكواد باركود دولية EAN-13 مع حساب الترقيم التسلسلي."
      },
      {
        title: "3. مقترح ثنائي الطور",
        desc: "إنشاء مقترح مسودة <code>AiPendingAction (ULID: act_cat_9041)</code> مع بصمة تشفير SHA-256."
      }
    ],
    ticket: `
      <div class="ticket-header">
        <span>PROPOSAL ID: act_cat_9041</span>
        <span>SHA-256: e3b0c44298fc1c149afbf4c8...</span>
      </div>
      <div class="ticket-body-content">
        <strong>المقترح الجاهز للاعتماد:</strong> إنشاء مسودة منتج "شنطة أكسفورد ريترو" (قطاعي: 650 ج.م | جملة: 480 ج.م) بعدد 4 متغيرات ألوان و 4 أكواد باركود معتمدة. لم يتم نشر المنتج حيًا في المتجر.
      </div>
      <div class="ticket-actions-bar">
        <span style="font-size: 0.75rem; color: var(--label-tertiary);">الضابط البشري: لن تنفذ أي حركة في قاعدة البيانات إلا بعد اختيارك:</span>
        <div style="display: flex; gap: 0.5rem;">
          <button class="apple-btn apple-btn-danger ticket-abort-btn" data-dept="catalog" type="button">إلغاء المقترح</button>
          <button class="apple-btn apple-btn-restore ticket-confirm-btn" data-dept="catalog" type="button">اعتماد وحفظ المسودة</button>
        </div>
      </div>
    `
  }
};

let currentCatalogScenarioKey = "cat_create";

function initCatalogScenarioSwitcher() {
  const chips = document.querySelectorAll(".scenario-chip[data-scenario]");
  if (!chips.length) return;

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      const scenarioKey = chip.getAttribute("data-scenario");
      if (!catalogCategoryScenarios[scenarioKey]) return;

      chips.forEach((c) => c.classList.remove("active"));
      chip.classList.add("active");
      currentCatalogScenarioKey = scenarioKey;

      applyCatalogScenario(scenarioKey);
    });
  });

  applyCatalogScenario("cat_create");
}

function applyCatalogScenario(key) {
  const sc = catalogCategoryScenarios[key];
  if (!sc) return;

  const bubbleText = document.querySelector("#dept-catalog .prompt-speech-bubble span");
  if (bubbleText) {
    bubbleText.textContent = sc.prompt;
  }

  const reactionBox = document.getElementById("reaction-box-catalog");
  if (reactionBox) {
    const phaseSteps = reactionBox.querySelectorAll(".phase-step");
    if (phaseSteps.length >= 3 && sc.phases.length >= 3) {
      phaseSteps[0].querySelector(".phase-step-title").innerHTML = sc.phases[0].title;
      phaseSteps[0].querySelector(".phase-step-desc").innerHTML = sc.phases[0].desc;

      phaseSteps[1].querySelector(".phase-step-title").innerHTML = sc.phases[1].title;
      phaseSteps[1].querySelector(".phase-step-desc").innerHTML = sc.phases[1].desc;

      phaseSteps[2].querySelector(".phase-step-title").innerHTML = sc.phases[2].title;
      phaseSteps[2].querySelector(".phase-step-desc").innerHTML = sc.phases[2].desc;
    }
  }

  initialTicketTemplates["catalog"] = sc.ticket;

  const ticket = document.getElementById("proposal-ticket-catalog");
  if (ticket) {
    ticket.innerHTML = sc.ticket;
    bindTicketButtons();
  }
}

/**
 * Confirms a proposal in PostgreSQL with double-entry commit
 */
function confirmDepartmentProposal(deptId) {
  const proposalTicket = document.getElementById(`proposal-ticket-${deptId}`);
  if (!proposalTicket) return;

  proposalTicket.innerHTML = `
    <div style="background: var(--state-active-bg); border: 1px solid var(--state-active-border); border-radius: var(--radius-sm); padding: 1.25rem; color: var(--state-active-text); font-size: 0.85rem; animation: fadeSlideIn 0.3s ease-out;">
      <div style="font-weight: 700; margin-bottom: 0.35rem; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
          <span>تم اعتماد العملية وترحيلها بنجاح في قاعدة البيانات (ACID Committed)</span>
        </div>
        <span style="font-family: var(--font-mono); font-size: 0.72rem; color: var(--state-active-text); background: rgba(74, 222, 128, 0.15); padding: 0.15rem 0.45rem; border-radius: 4px;">PostgreSQL 16 • 14ms</span>
      </div>
      <div style="line-height: 1.5; margin-bottom: 0.85rem;">
        تم التحقق من بصمة التشفير وتطبيق العملية بنجاح. سُجلت الحركة في دفتر الأستاذ العام وسجل التدقيق الأمني (SIEM). نافذة التراجع الذكي (Undo) متاحة لمدة 15 دقيقة بتجزئة SHA-256.
      </div>
      <div style="display: flex; justify-content: flex-end;">
        <button class="apple-btn apple-btn-secondary reset-trial-btn" data-dept="${deptId}" type="button" style="font-size: 0.75rem; padding: 0.35rem 0.75rem;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          <span>إعادة تجربة المحاكاة</span>
        </button>
      </div>
    </div>
  `;

  const resetBtn = proposalTicket.querySelector(".reset-trial-btn");
  if (resetBtn) {
    resetBtn.addEventListener("click", () => {
      runDepartmentSimulation(deptId);
    });
  }

  logSiemEvent({
    status: "COMMITTED (201)",
    color: "var(--state-active)",
    actor: "human_supervisor",
    action: `confirm_${deptId}_action`,
    details: `ACID: Committed to PostgreSQL | Hash Verified | Undo TTL: 15 mins`
  });
}

/**
 * Aborts a proposal with zero side effects
 */
function abortDepartmentProposal(deptId) {
  const proposalTicket = document.getElementById(`proposal-ticket-${deptId}`);
  if (!proposalTicket) return;

  proposalTicket.innerHTML = `
    <div style="background: var(--surface-primary); border: 1px solid var(--border-hairline); border-radius: var(--radius-sm); padding: 1.25rem; color: var(--label-secondary); font-size: 0.85rem; animation: fadeSlideIn 0.3s ease-out;">
      <div style="font-weight: 700; margin-bottom: 0.35rem; color: var(--state-lock); display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <span>تم إلغاء المقترح دون أي أثر (Action Aborted by Supervisor)</span>
        </div>
        <span style="font-family: var(--font-mono); font-size: 0.72rem; color: var(--label-tertiary);">Zero Side Effects</span>
      </div>
      <div style="line-height: 1.5; margin-bottom: 0.85rem;">
        تم شطب المقترح المعلق بالكامل دون إجراء أي تعديل على بيانات المنظومة. الأرصدة والقيود وسجلات الكتالوج بقيت دون أي مساس.
      </div>
      <div style="display: flex; justify-content: flex-end;">
        <button class="apple-btn apple-btn-secondary reset-trial-btn" data-dept="${deptId}" type="button" style="font-size: 0.75rem; padding: 0.35rem 0.75rem;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          <span>إعادة تجربة المحاكاة</span>
        </button>
      </div>
    </div>
  `;

  const resetBtn = proposalTicket.querySelector(".reset-trial-btn");
  if (resetBtn) {
    resetBtn.addEventListener("click", () => {
      runDepartmentSimulation(deptId);
    });
  }

  logSiemEvent({
    status: "ABORTED (200)",
    color: "var(--label-secondary)",
    actor: "human_supervisor",
    action: `abort_${deptId}_action`,
    details: `Clean rollback. Pending action record purged. No DB mutation.`
  });
}

/**
 * Handles individual Kill-Switch for each department
 */
function initKillSwitchHandlers() {
  document.querySelectorAll(".dept-killswitch-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      const deptId = btn.getAttribute("data-dept");
      toggleDepartmentLock(deptId);
    });
  });
}

function toggleDepartmentLock(deptId) {
  const state = domainStates[deptId];
  state.isLocked = !state.isLocked;

  const card = document.getElementById(`dept-${deptId}`);
  const btn = document.querySelector(`.dept-killswitch-btn[data-dept="${deptId}"]`);
  const statusPill = document.getElementById(`status-pill-${deptId}`);

  if (state.isLocked) {
    if (card) card.classList.add("is-locked");
    if (statusPill) {
      statusPill.className = "status-indicator-pill locked";
      statusPill.innerHTML = `
        <span class="pulse-pip" style="background-color: var(--state-lock);"></span>
        <span>صلاحيات مسحوبة (إغلاق أمني فوري)</span>
      `;
    }
    if (btn) {
      btn.className = "apple-btn apple-btn-restore dept-killswitch-btn";
      btn.innerHTML = `
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
        <span>استعادة الصلاحيات</span>
      `;
    }

    logSiemEvent({
      status: "SECURITY_REVOKED (403)",
      color: "var(--state-lock)",
      actor: "security_admin",
      action: `killswitch_engaged_${deptId}`,
      details: `INSTANT REVOKE: Sanctum tokens invalidated in 21ms. Socket severed.`
    });
  } else {
    if (card) card.classList.remove("is-locked");
    if (statusPill) {
      statusPill.className = "status-indicator-pill";
      statusPill.innerHTML = `
        <span class="pulse-pip" style="background-color: var(--state-active);"></span>
        <span>صلاحيات مدارة (نشط ومراقب)</span>
      `;
    }
    if (btn) {
      btn.className = "apple-btn apple-btn-danger dept-killswitch-btn";
      btn.innerHTML = `
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
        <span>محاكاة الإغلاق الفوري (Kill-Switch)</span>
      `;
    }

    // Hide any previous denied alert
    const reactionBox = document.getElementById(`reaction-box-${deptId}`);
    if (reactionBox) {
      const banner = reactionBox.querySelector(".denied-alert-banner");
      if (banner) banner.style.display = "none";
    }

    logSiemEvent({
      status: "SECURITY_RESTORED (200)",
      color: "var(--state-active)",
      actor: "security_admin",
      action: `killswitch_released_${deptId}`,
      details: `PERMISSIONS RESTORED: Managed IAM roles re-authenticated.`
    });
  }

  updateMasterLockdownState();
}

/**
 * Master Cupertino Lockdown Switch
 */
function initMasterLockdown() {
  const masterSwitch = document.getElementById("master-lockdown-switch");
  if (!masterSwitch) return;

  masterSwitch.addEventListener("change", (e) => {
    const shouldLock = e.target.checked;
    Object.keys(domainStates).forEach((deptId) => {
      domainStates[deptId].isLocked = shouldLock;
      const card = document.getElementById(`dept-${deptId}`);
      const btn = document.querySelector(`.dept-killswitch-btn[data-dept="${deptId}"]`);
      const statusPill = document.getElementById(`status-pill-${deptId}`);

      if (shouldLock) {
        if (card) card.classList.add("is-locked");
        if (statusPill) {
          statusPill.className = "status-indicator-pill locked";
          statusPill.innerHTML = `<span class="pulse-pip" style="background-color: var(--state-lock);"></span><span>صلاحيات مسحوبة (إغلاق فوري)</span>`;
        }
        if (btn) {
          btn.className = "apple-btn apple-btn-restore dept-killswitch-btn";
          btn.innerHTML = `<span>استعادة الصلاحيات</span>`;
        }
      } else {
        if (card) card.classList.remove("is-locked");
        if (statusPill) {
          statusPill.className = "status-indicator-pill";
          statusPill.innerHTML = `<span class="pulse-pip" style="background-color: var(--state-active);"></span><span>صلاحيات مدارة (نشط)</span>`;
        }
        if (btn) {
          btn.className = "apple-btn apple-btn-danger dept-killswitch-btn";
          btn.innerHTML = `<span>محاكاة الإغلاق الفوري</span>`;
        }
      }
    });

    updateMasterLockdownState();

    logSiemEvent({
      status: shouldLock ? "MASTER_LOCKDOWN_ENGAGED (503)" : "MASTER_LOCKDOWN_RELEASED (200)",
      color: shouldLock ? "var(--state-lock)" : "var(--state-active)",
      actor: "chief_security_officer",
      action: shouldLock ? "emergency_lockdown_all" : "restore_normal_operations",
      details: shouldLock ? "ALL 10 DEPARTMENTS SEVERED. Air-gapped isolation in 38ms." : "All 10 departments returned to normal operation."
    });
  });
}

function updateMasterLockdownState() {
  const lockedCount = Object.values(domainStates).filter((s) => s.isLocked).length;
  const masterSwitch = document.getElementById("master-lockdown-switch");
  const label = document.getElementById("master-lockdown-label");

  if (masterSwitch) {
    masterSwitch.checked = lockedCount === 10;
  }

  if (label) {
    if (lockedCount === 10) {
      label.textContent = "إغلاق أمني شامل (كافة الأقسام معزولة)";
      label.style.color = "var(--state-lock)";
    } else if (lockedCount > 0) {
      label.textContent = `${lockedCount} أقسام تحت الإغلاق الأمني`;
      label.style.color = "var(--state-warn)";
    } else {
      label.textContent = "المنظومة آمنة ونشطة بالكامل (10/10)";
      label.style.color = "var(--label-primary)";
    }
  }
}

/**
 * Appends live event to SIEM Drawer
 */
function logSiemEvent({ status, color, actor, action, details }) {
  const drawerContent = document.querySelector(".drawer-content");
  if (!drawerContent) return;

  const now = new Date();
  const timeStr = now.toISOString().replace("T", " ").substring(0, 19) + " UTC";

  const item = document.createElement("div");
  item.className = "audit-log-item";
  item.style.animation = "fadeSlideIn 0.3s ease-out";
  item.innerHTML = `
    <div class="log-meta-line">
      <span>${timeStr}</span>
      <span style="color: ${color}; font-weight: 600;">${status}</span>
    </div>
    <div class="log-action-text">ACTOR: ${actor} | ACTION: ${action}</div>
    <div style="color: var(--label-tertiary);">${details}</div>
  `;

  // Prepend so latest appears at the top
  drawerContent.insertBefore(item, drawerContent.firstChild);
}

/**
 * SIEM Drawer Manager
 */
function initSIEMDrawer() {
  const openBtn = document.getElementById("audit-drawer-open-btn");
  const closeBtn = document.getElementById("audit-drawer-close-btn");
  const backdrop = document.getElementById("audit-drawer-backdrop");

  if (openBtn && backdrop) {
    openBtn.addEventListener("click", () => backdrop.classList.add("active"));
  }
  if (closeBtn && backdrop) {
    closeBtn.addEventListener("click", () => backdrop.classList.remove("active"));
  }
  if (backdrop) {
    backdrop.addEventListener("click", (e) => {
      if (e.target === backdrop) backdrop.classList.remove("active");
    });
  }
}

/**
 * Print Executive Dossier Manager
 */
function initPrintButton() {
  const btn = document.getElementById("print-dossier-btn");
  if (btn) {
    btn.addEventListener("click", () => window.print());
  }
}
