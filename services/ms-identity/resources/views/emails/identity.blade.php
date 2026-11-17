<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>{{ $headline }} · AgroCloud</title>
    <style>
        @media only screen and (max-width: 620px) {
            .outer-pad { padding: 20px 12px !important; }
            .content-pad { padding: 32px 24px !important; }
            .header-pad { padding: 25px 24px !important; }
            .email-title { font-size: 26px !important; line-height: 34px !important; }
            .button-link { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#eff4f6;color:#1c3141;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
    <div style="display:none;font-size:1px;color:#eff4f6;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        {{ $preheader }}
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background-color:#eff4f6;">
        <tr>
            <td align="center" class="outer-pad" style="padding:48px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;max-width:600px;border-collapse:separate;border-spacing:0;">
                    <tr>
                        <td style="padding:0 0 20px 4px;text-align:left;">
                            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;letter-spacing:1.4px;color:#426273;">AGROCLOUD <span style="color:#16a776;">●</span> NOTIFICACIONES</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #dbe6eb;border-radius:20px;overflow:hidden;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
                                <tr>
                                    <td class="header-pad" style="background-color:#0d2030;padding:28px 42px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                            <tr>
                                                <td align="center" valign="middle" width="46" height="46" bgcolor="#21c489" style="width:46px;height:46px;border-radius:12px;color:#082719;font-size:20px;line-height:46px;text-align:center;font-weight:800;">
                                                    A
                                                </td>
                                                <td style="padding-left:13px;color:#ffffff;font-size:23px;font-weight:750;letter-spacing:-0.6px;">
                                                    Agro<span style="color:#33d99d;">Cloud</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:15px 0 0;color:#9bb1bd;font-size:12px;line-height:18px;letter-spacing:1.2px;font-weight:700;">GESTIÓN AGRÍCOLA INTELIGENTE</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td height="5" bgcolor="#20c68d" style="height:5px;font-size:0;line-height:0;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td class="content-pad" style="padding:42px 42px 38px;">
                                        <p style="margin:0 0 17px;font-size:11px;font-weight:800;line-height:16px;color:#128864;letter-spacing:1.65px;">{{ $eyebrow }}</p>
                                        <h1 class="email-title" style="margin:0 0 18px;font-size:31px;line-height:39px;letter-spacing:-0.9px;color:#10283a;font-weight:800;">{{ $headline }}</h1>
                                        <p style="margin:0 0 12px;color:#1f3644;font-size:17px;line-height:27px;font-weight:700;">{{ $lead }}</p>
                                        <p style="margin:0;color:#4a6271;font-size:15px;line-height:26px;">{{ $message }}</p>

                                        @if ($detailText)
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:24px;border-collapse:separate;border-spacing:0;background:#f4f8fa;border-left:4px solid #20c68d;border-radius:6px;">
                                            <tr>
                                                <td style="padding:17px 20px;">
                                                    <p style="margin:0 0 8px;color:#315667;font-size:12px;font-weight:800;letter-spacing:.6px;">{{ $detailLabel }}</p>
                                                    <p style="margin:0;color:#243e4b;font-size:14px;line-height:23px;overflow-wrap:anywhere;">{{ $detailText }}</p>
                                                </td>
                                            </tr>
                                        </table>
                                        @endif

                                        @if ($actionUrl && $actionLabel)
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:29px 0 23px;border-collapse:separate;border-spacing:0;">
                                            <tr>
                                                <td align="center" bgcolor="#20c68d" style="background:#20c68d;border-radius:11px;">
                                                    <a class="button-link" href="{{ $actionUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:17px 29px;font-size:15px;line-height:20px;font-weight:800;color:#082d23;text-decoration:none;border-radius:11px;">{{ $actionLabel }} &rarr;</a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:0 0 11px;color:#546e7b;font-size:12px;line-height:20px;">{{ $expiry }}</p>
                                        <p style="margin:0;color:#708796;font-size:12px;line-height:20px;">¿El botón no funciona? Copia este enlace en tu navegador:</p>
                                        <p style="margin:5px 0 0;font-size:12px;line-height:20px;overflow-wrap:anywhere;word-break:break-all;">
                                            <a href="{{ $actionUrl }}" style="color:#087e62;text-decoration:underline;overflow-wrap:anywhere;word-break:break-all;">{{ $actionUrl }}</a>
                                        </p>
                                        @endif

                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:30px;border-collapse:separate;border-spacing:0;background-color:#f2f8f5;border-radius:10px;">
                                            <tr>
                                                <td style="padding:17px 19px;">
                                                    <p style="margin:0 0 6px;font-size:12px;color:#157a5d;font-weight:800;">SEGURIDAD DE TU CUENTA</p>
                                                    <p style="margin:0;color:#416157;font-size:13px;line-height:22px;">{{ $notice }}</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:28px 0 0;font-size:14px;line-height:23px;color:#4a6271;">El equipo de <strong style="color:#163647;">AgroCloud</strong></p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:23px 18px 6px;color:#7b8e99;font-size:12px;line-height:21px;">
                            &copy; {{ date('Y') }} AgroCloud · Plataforma de gestión agrícola.<br>
                            Este es un correo automático relacionado con tu cuenta. No respondas a este mensaje.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
