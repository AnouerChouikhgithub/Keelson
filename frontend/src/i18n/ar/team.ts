export const team = {
  title: 'الفريق',
  subtitle: 'الأعضاء والأدوار والدعوات في مساحة العمل هذه.',
  yourTitle: 'عضويتك',
  yourHint: 'مقروءة من جلستك ومن العضويات التي أعادتها الواجهة.',
  tableCaption: 'عضويتك في مساحة العمل هذه',
  columns: {
    name: 'العضو',
    email: 'البريد الإلكتروني',
    role: 'الدور',
  },
  you: 'أنت',
  roleNote: (role: string) => `أنت ${role} في مساحة العمل هذه. الخادم يقرر كل طلب؛ وإخفاء إجراء مجرد تسهيل.`,
  gateTitle: 'إدارة الفريق غير متصلة',
  gateBody:
    'لا تحتوي الواجهة على نقاط نهاية لعرض الأعضاء أو تغيير دور أو إزالة عضو أو مغادرة مؤسسة أو إصدار دعوات. ولا تُعرض أعضاء تجريبيون بدلًا منها.',
  gateEndpoint: 'GET/PATCH/DELETE /api/organizations/{organizationId}/members',
  lastOwnerNote: 'لا يمكن تطبيق قواعد المالك، مثل «لا يمكن إزالة آخر مالك»، قبل وجود نقطة النهاية هذه.',
}

export const notifications = {
  title: 'الإشعارات',
  subtitle: 'نشاط الفواتير والفريق والحساب في مساحة العمل هذه.',
  gateTitle: 'الإشعارات غير متصلة',
  gateBody:
    'لا تحتوي الواجهة على تغذية إشعارات ولا حالة قراءة، لذا لا يظهر شعار في الترويسة ولا تعرض هذه الشاشة إشعارات تجريبية.',
  gateEndpoint: 'GET /api/notifications وPOST /api/notifications/{id}/read',
}
