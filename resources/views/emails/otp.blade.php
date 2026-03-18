<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Code de réinitialisation</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFF; font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFF; padding:40px 0;">
    <tr>
      <td align="center">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #E2E8F0; border-radius:8px; overflow:hidden;">

          <!-- Header -->
          <tr>
            <td style="background:#2563EB; padding:28px 40px;">
              <table cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <span style="font-size:20px; font-weight:800; color:#ffffff; letter-spacing:-0.5px;">
                      Credential App
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:36px 40px;">
              <p style="font-size:22px; font-weight:700; color:#0F172A; margin:0 0 8px 0;">
                Code de réinitialisation
              </p>
              <p style="font-size:14px; color:#64748B; margin:0 0 28px 0; line-height:1.6;">
                Vous avez demandé à réinitialiser votre mot de passe applicatif.
                Voici votre code à usage unique :
              </p>

              <!-- Code OTP -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                <tr>
                  <td align="center">
                    <div style="display:inline-block; background:#F0F7FF; border:1px solid #BFDBFE; border-radius:8px; padding:20px 48px;">
                      <span style="font-size:40px; font-weight:800; color:#2563EB; letter-spacing:12px; font-family:monospace;">
                        {{ $code }}
                      </span>
                    </div>
                  </td>
                </tr>
              </table>

              <p style="font-size:13px; color:#94A3B8; margin:0 0 8px 0;">
                Ce code expire dans <strong style="color:#0F172A;">5 minutes</strong>.
              </p>
              <p style="font-size:13px; color:#94A3B8; margin:0; line-height:1.6;">
                Si vous n'avez pas demandé cette réinitialisation, ignorez cet email.
                Ce message ne vous est peut-être pas destiné — vérifiez que vous utilisez
                la bonne adresse email.
              </p>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding:0 40px;">
              <div style="height:1px; background:#F1F5F9;"></div>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:20px 40px;">
              <p style="font-size:12px; color:#CBD5E1; margin:0;">
                &copy; {{ date('Y') }} Credential App &mdash; Ne partagez jamais ce code.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
