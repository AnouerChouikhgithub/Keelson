<?php

declare(strict_types=1);

namespace App\Guide;

/**
 * The fixed strings the assistant answers without asking a model.
 *
 * A question the knowledge base does not cover is answered locally: there is nothing
 * to ground a model answer on, so calling one could only invent product behaviour (and
 * spend the tenant's budget doing it). The message is therefore part of the API, one
 * per supported locale.
 */
final class GuideMessages
{
    public const LOCALES = ['en', 'fr', 'ar'];

    private const NOT_COVERED = [
        'en' => "I don't have documentation covering that yet. Try asking about organizations, team roles, customers, invoices, notifications, the audit trail or AI settings.",
        'fr' => "Je n'ai pas encore de documentation sur ce sujet. Essayez une question sur les organisations, les rôles, les clients, les factures, les notifications, le journal d'audit ou les paramètres IA.",
        'ar' => 'ليس لديّ توثيق يغطي ذلك بعد. جرّب سؤالاً عن المؤسسات أو أدوار الفريق أو العملاء أو الفواتير أو الإشعارات أو سجل التدقيق أو إعدادات الذكاء الاصطناعي.',
    ];

    public static function notCovered(string $locale): string
    {
        return self::NOT_COVERED[$locale] ?? self::NOT_COVERED['en'];
    }
}
