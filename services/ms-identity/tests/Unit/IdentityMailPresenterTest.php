<?php

namespace Tests\Unit;

use App\Mail\IdentityMailPresenter;
use PHPUnit\Framework\TestCase;

class IdentityMailPresenterTest extends TestCase
{
    public function test_invitation_contains_secure_action_without_changing_token(): void
    {
        $url = 'https://portal.example.test/activate?user_id=5&token=sample123';
        $result = IdentityMailPresenter::make('INVITATION', 'Enlace de un solo uso, valido 72 horas: '.$url);

        $this->assertSame($url, $result['actionUrl']);
        $this->assertSame('Activar mi cuenta', $result['actionLabel']);
        $this->assertStringContainsString('72 horas', $result['expiry']);
    }

    public function test_password_reset_is_distinct_from_invitation(): void
    {
        $result = IdentityMailPresenter::make('PASSWORD_RESET', 'El enlace vence en 60 minutos: https://portal.example.test/reset-password?token=abc');

        $this->assertSame('Crear nueva contraseña', $result['actionLabel']);
        $this->assertStringContainsString('60 minutos', $result['expiry']);
    }

    public function test_rejection_reason_is_preserved_as_plain_data(): void
    {
        $reason = '<script>prueba</script> Sin información suficiente';
        $result = IdentityMailPresenter::make('ACCESS_REJECTED', 'Tu solicitud fue rechazada. Motivo: '.$reason);

        $this->assertSame($reason, $result['detailText']);
        $this->assertNull($result['actionUrl']);
    }

    public function test_untrusted_url_schemes_do_not_create_action_buttons(): void
    {
        $result = IdentityMailPresenter::make('INVITATION', 'Enlace: javascript:alert(1)');

        $this->assertNull($result['actionUrl']);
        $this->assertSame('', $result['actionLabel']);
    }
}
