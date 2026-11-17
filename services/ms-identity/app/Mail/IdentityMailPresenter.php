<?php

namespace App\Mail;

/**
 * Presentation-only mapping for Identity's existing security messages.
 * The original text remains the source of the tokenised URL and rejection reason.
 * No secret, token, or message is logged.
 */
final class IdentityMailPresenter
{
    /** @return array<string, string|null> */
    public static function make(string $kind, string $body): array
    {
        // Older queued jobs did not include the kind. Recognize their original message
        // safely so retries receive the same branded call-to-action.
        if ($kind === 'GENERAL') {
            $kind = match (true) {
                str_contains($body, 'Enlace de un solo uso') => 'INVITATION',
                str_contains($body, 'El enlace vence en 60 minutos') => 'PASSWORD_RESET',
                str_contains($body, 'Enlace valido 24 horas') => 'EMAIL_VERIFICATION',
                str_contains($body, 'Tu solicitud fue rechazada. Motivo:') => 'ACCESS_REJECTED',
                default => 'GENERAL',
            };
        }

        $templates = [
            'INVITATION' => [
                'eyebrow' => 'BIENVENIDA A AGROCLOUD',
                'headline' => 'Tu cuenta está lista para activarse',
                'preheader' => 'Activa tu acceso a AgroCloud y comienza a gestionar tu operación agrícola.',
                'lead' => '¡Bienvenido a AgroCloud!',
                'message' => 'Tu organización ya puede comenzar a utilizar la plataforma. Activa tu cuenta y crea una contraseña segura para ingresar.',
                'actionLabel' => 'Activar mi cuenta',
                'expiry' => 'Este enlace vence en 72 horas y solo puede utilizarse una vez.',
                'notice' => 'Si no esperabas esta invitación, puedes ignorar este mensaje.',
            ],
            'PASSWORD_RESET' => [
                'eyebrow' => 'RECUPERACIÓN DE CUENTA',
                'headline' => 'Restablece tu contraseña',
                'preheader' => 'Recibimos una solicitud para recuperar el acceso a tu cuenta AgroCloud.',
                'lead' => '¿Necesitas volver a ingresar?',
                'message' => 'Recibimos una solicitud para restablecer tu contraseña. Utiliza el botón para establecer una nueva y recuperar el acceso.',
                'actionLabel' => 'Crear nueva contraseña',
                'expiry' => 'Por seguridad, este enlace vence en 60 minutos.',
                'notice' => 'Si no solicitaste este cambio, ignora el correo. Tu contraseña actual seguirá siendo válida.',
            ],
            'EMAIL_VERIFICATION' => [
                'eyebrow' => 'VERIFICACIÓN DE CORREO',
                'headline' => 'Confirma tu nuevo correo',
                'preheader' => 'Confirma esta dirección para continuar utilizando AgroCloud.',
                'lead' => 'Solo falta confirmar tu dirección',
                'message' => 'Para completar el cambio de correo, verifica que esta dirección te pertenece mediante el siguiente botón.',
                'actionLabel' => 'Confirmar correo',
                'expiry' => 'El enlace es válido durante 24 horas.',
                'notice' => 'Si no solicitaste cambiar tu correo, no utilices el enlace y comunícate con tu administrador.',
            ],
            'ACCESS_REJECTED' => [
                'eyebrow' => 'ESTADO DE SOLICITUD',
                'headline' => 'Resultado de tu solicitud',
                'preheader' => 'Tenemos una actualización sobre tu solicitud de acceso a AgroCloud.',
                'lead' => 'Gracias por tu interés en AgroCloud',
                'message' => 'Hemos revisado tu solicitud empresarial. En esta oportunidad no fue posible aprobar el acceso.',
                'actionLabel' => '',
                'expiry' => '',
                'notice' => 'Si necesitas más información, comunícate por los canales oficiales de AgroCloud.',
            ],
            'PASSWORD_CHANGED' => [
                'eyebrow' => 'AVISO DE SEGURIDAD',
                'headline' => 'Tu contraseña fue actualizada',
                'preheader' => 'Se realizó un cambio de contraseña en tu cuenta AgroCloud.',
                'lead' => 'Tu seguridad es importante',
                'message' => 'Te confirmamos que se actualizó la contraseña de tu cuenta.',
                'actionLabel' => '',
                'expiry' => '',
                'notice' => 'Si tú no realizaste este cambio, contacta inmediatamente al administrador de tu organización.',
            ],
            'EMAIL_CHANGED' => [
                'eyebrow' => 'AVISO DE SEGURIDAD',
                'headline' => 'Se actualizó tu correo',
                'preheader' => 'Se ha modificado el correo de acceso a tu cuenta AgroCloud.',
                'lead' => 'Confirmación de seguridad',
                'message' => 'La dirección de correo asociada a tu cuenta fue modificada. Este aviso se envía a tu dirección anterior.',
                'actionLabel' => '',
                'expiry' => '',
                'notice' => 'Si tú no autorizaste este cambio, comunícate inmediatamente con el administrador de tu organización.',
            ],
        ];

        $presentation = $templates[$kind] ?? [
            'eyebrow' => 'NOTIFICACIÓN DE CUENTA',
            'headline' => 'Una actualización de AgroCloud',
            'preheader' => 'Tienes una comunicación importante de AgroCloud.',
            'lead' => 'Hola,',
            'message' => $body,
            'actionLabel' => '',
            'expiry' => '',
            'notice' => 'Este es un mensaje automático relacionado con tu cuenta.',
        ];

        // URLs are produced by AccountSecurity::url; never interpolate arbitrary HTML.
        // Strip punctuation around a pasted URL while preserving query parameters.
        $actionUrl = null;
        if (preg_match('~https?://[^\s<>"\x27]+~u', $body, $matches) === 1) {
            $candidate = rtrim($matches[0], '.,;)>');
            $parts = parse_url($candidate);
            $scheme = $parts['scheme'] ?? null;
            $host = strtolower($parts['host'] ?? '');
            if ($scheme === 'https' || (
                $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)
            )) {
                $actionUrl = $candidate;
            }
        }

        $detailLabel = null;
        $detailText = null;
        if ($kind === 'ACCESS_REJECTED') {
            $detailLabel = 'Motivo indicado';
            $detailText = trim((string) preg_replace('/^.*?Motivo:\s*/su', '', $body));
            if ($detailText === $body) {
                $detailText = 'No se especificó un motivo adicional.';
            }
        }

        // Do not lose the original message if a known type has no action URL.
        if ($actionUrl === null && in_array($kind, ['INVITATION', 'PASSWORD_RESET', 'EMAIL_VERIFICATION'], true)) {
            $presentation['message'] = $body;
            $presentation['actionLabel'] = '';
        }

        $presentation['bodyText'] = $presentation['message'];
        unset($presentation['message']);

        return [
            ...$presentation,
            'actionUrl' => $actionUrl,
            'detailLabel' => $detailLabel,
            'detailText' => $detailText,
            'brand' => 'AgroCloud',
        ];
    }
}
