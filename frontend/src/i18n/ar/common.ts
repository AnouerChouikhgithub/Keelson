export const common = {
  languageName: 'العربية',
  skipToContent: 'انتقل إلى المحتوى',
  loading: 'جارٍ التحميل…',
  loadingMore: 'جارٍ التحميل…',
  retry: 'إعادة المحاولة',
  reload: 'إعادة التحميل',
  cancel: 'إلغاء',
  close: 'إغلاق',
  dismiss: 'إغلاق الإشعار',
  save: 'حفظ',
  saving: 'جارٍ الحفظ…',
  create: 'إنشاء',
  edit: 'تعديل',
  delete: 'حذف',
  back: 'رجوع',
  search: 'بحث',
  clearSearch: 'مسح البحث',
  loadMore: 'تحميل المزيد',
  loadedCount: (count: number) => (count === 0 ? 'لا سجلات محمّلة' : count === 1 ? 'سجل واحد محمّل' : count === 2 ? 'سجلان محمّلان' : `${count} سجلات محمّلة`),
  actions: 'إجراءات',
  print: 'طباعة',
  status: 'الحالة',
  amount: 'المبلغ',
  name: 'الاسم',
  email: 'البريد الإلكتروني',
  phone: 'الهاتف',
  role: 'الدور',
  organization: 'المؤسسة',
  sessionExpired: 'تغيّرت جلستك. سجّل الدخول مرة أخرى.',
}

export const nav = {
  workspace: 'مساحة العمل',
  mainNav: 'التنقل الرئيسي',
  breadcrumb: 'مسار التنقل',
  dashboard: 'لوحة التحكم',
  invoices: 'الفواتير',
  customers: 'العملاء',
  team: 'الفريق',
  audit: 'سجل التدقيق',
  settings: 'الإعدادات',
  account: 'حسابي',
  notifications: 'الإشعارات',
  toggleNav: 'إظهار أو إخفاء التنقل',
  closeNav: 'إغلاق التنقل',
  currentOrg: (name: string) => `المؤسسة الحالية: ${name}`,
  switchOrg: 'تبديل المؤسسة',
  yourWorkspaces: 'مساحاتك',
  createOrg: 'إنشاء مؤسسة',
  menuFootnote: 'الرابط يحدد المؤسسة؛ والخادم يعيد التفويض في كل طلب.',
  openProfileMenu: 'فتح قائمة الملف الشخصي',
  profileMenuLabel: 'قائمة الحساب',
}

export const page = {
  dashboard: 'لوحة التحكم',
  invoices: 'الفواتير',
  newInvoice: 'فاتورة جديدة',
  invoice: 'فاتورة',
  customers: 'العملاء',
  team: 'الفريق',
  audit: 'سجل التدقيق',
  settings: 'الإعدادات',
  account: 'حسابي',
  notifications: 'الإشعارات',
  onboarding: 'التهيئة',
  notFound: 'الصفحة غير موجودة',
}

export const theme = {
  appearance: 'المظهر',
  light: 'فاتح',
  dark: 'داكن',
  system: 'النظام',
  setTheme: (label: string) => `مظهر ${label}`,
}

export const search = {
  title: 'البحث في Keelson',
  placeholder: 'ابحث في الصفحات والمؤسسات…',
  open: 'فتح البحث',
  close: 'إغلاق البحث',
  shortcutHint: (keys: string) => `اضغط ${keys} لفتح البحث`,
  noResults: (query: string) => `لا نتائج لـ «${query}»`,
  noResultsHint: 'جرّب اسم صفحة أو إحدى مؤسساتك.',
  resultCount: (count: number) => (count === 0 ? 'لا نتائج' : count === 1 ? 'نتيجة واحدة' : `${count} نتائج`),
  hint: 'استخدم الأسهم للتنقل وEnter للفتح.',
  kinds: {
    page: 'صفحة',
    organization: 'مؤسسة',
  },
}

export const api = {
  loading: 'جارٍ التحميل…',
  offline: 'تعذّر الوصول إلى الخادم. شغّل الواجهة البرمجية وتحقق من الاتصال ثم أعد المحاولة.',
  timeout: 'استغرق الخادم وقتًا طويلًا للرد. أعد المحاولة.',
  unauthorized: 'جلستك لم تعد صالحة. سجّل الدخول مرة أخرى للمتابعة.',
  forbidden: 'دورك في مساحة العمل لا يسمح بهذا الإجراء.',
  notFound: 'هذا السجل لم يعد موجودًا.',
  conflict: 'تغيّر السجل على الخادم. تم تحميل أحدث نسخة.',
  invalid: 'رفض الخادم بعض القيم.',
  rateLimited: 'طلبات كثيرة جدًا. انتظر لحظة ثم أعد المحاولة.',
  server: 'تعذّر على الخادم إكمال الطلب.',
  unexpected: 'أعاد الخادم استجابة غير متوقعة.',
  listFailed: 'تعذّر تحميل هذه القائمة.',
}

export const profile = {
  myAccount: 'حسابي',
  notifications: 'الإشعارات',
  settings: 'الإعدادات',
  logout: 'تسجيل الخروج',
}

export const session = {
  cardTitle: 'الجلسة',
  signingOut: 'جارٍ تسجيل الخروج…',
  readFailed: 'تعذّرت قراءة الجلسة من الخادم. شغّل الواجهة البرمجية ثم أعد المحاولة.',
}

export const status = {
  paid: 'مدفوعة',
  sent: 'مرسلة',
  overdue: 'متأخرة',
  draft: 'مسودة',
}

export const roles = {
  owner: 'المالك',
  admin: 'مدير',
  member: 'عضو',
  viewer: 'مشاهد',
}

export const notFound = {
  eyebrow: '404',
  title: 'هذه الصفحة غير موجودة',
  body: 'قد يكون العنوان خاطئًا أو تم حذف السجل. اختر مساحة عمل من المبدّل للمتابعة.',
  backToDashboard: 'الانتقال إلى لوحة التحكم',
}

export const gate = {
  eyebrow: 'غير متصل',
  endpointLabel: 'نقطة النهاية المفقودة',
  sourceNote: 'لا تُعرض أي سجلات تجريبية في هذه الشاشة.',
  backToDashboard: 'العودة إلى لوحة التحكم',
}

export const toast = {
  region: 'رسائل الحالة',
  dismiss: 'إغلاق الإشعار',
}

export const errors = {
  boundaryTitle: 'توقفت هذه الشاشة عن الاستجابة',
  boundaryBody: 'تم التقاط خطأ غير متوقع في الواجهة. بياناتك على الخادم؛ أعد تحميل الشاشة للمتابعة.',
  boundaryReload: 'إعادة تحميل الشاشة',
  title: 'حدث خطأ ما',
}
