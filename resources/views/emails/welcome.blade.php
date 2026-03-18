<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bienvenue sur Credential App</title>
</head>
<body style="margin:0; padding:0; background:#F8FAFF; font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFF; padding:40px 0;">
    <tr>
      <td align="center">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #E2E8F0; border-radius:8px; overflow:hidden;">

          <!-- Header -->
          <tr>
            <td style="background:#2563EB; padding:28px 40px;">
              <span style="font-size:20px; font-weight:800; color:#ffffff; letter-spacing:-0.5px;">
                Credential App
              </span>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:36px 40px;">
              <p style="font-size:22px; font-weight:700; color:#0F172A; margin:0 0 8px 0;">
                Bienvenue, {{ $user->name }} !
              </p>
              <p style="font-size:14px; color:#64748B; margin:0 0 24px 0; line-height:1.6;">
                Votre compte a été créé avec succès via Google. Vous pouvez maintenant
                gérer tous vos credentials en toute sécurité.
              </p>

              <table cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td style="padding:12px 16px; background:#F0F7FF; border-radius:5px; border-left:3px solid #2563EB;">
                    <p style="font-size:13px; color:#1D4ED8; margin:0; font-weight:600;">
                      Prochaine étape
                    </p>
                    <p style="font-size:13px; color:#374151; margin:4px 0 0 0;">
                      Définissez votre mot de passe applicatif pour protéger l'accès à vos données sensibles.
                    </p>
                  </td>
                </tr>
              </table>

              <p style="font-size:13px; color:#94A3B8; margin:0; line-height:1.6;">
                Si vous n'avez pas créé ce compte, ignorez cet email ou contactez-nous.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding:0 40px;">
              <div style="height:1px; background:#F1F5F9;"></div>
            </td>
          </tr>

          <tr>
            <td style="padding:20px 40px;">
              <p style="font-size:12px; color:#CBD5E1; margin:0;">
                &copy; {{ date('Y') }} Credential App &mdash; Vos données sont chiffrées et sécurisées.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
