<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Réinitialisation de votre mot de passe</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f5; padding: 32px 0; margin: 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; padding: 32px;">
                    <tr>
                        <td>
                            <p style="font-size: 16px; color: #18181b;">Bonjour {{ $firstName }},</p>
                            <p style="font-size: 16px; color: #18181b;">Voici votre code pour choisir un nouveau mot de passe :</p>
                            <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center; color: #18181b; margin: 32px 0;">{{ $code }}</p>
                            <p style="font-size: 14px; color: #71717a;">Ce code expire dans 10 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email : votre mot de passe ne change pas.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
