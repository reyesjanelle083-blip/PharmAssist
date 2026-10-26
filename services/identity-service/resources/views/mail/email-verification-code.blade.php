<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your PharmAssist email</title>
</head>

<body style="margin:0; padding:0; background:#f4f7f9; font-family:Arial, Helvetica, sans-serif; color:#333333;">

<table width="100%" cellpadding="0" cellspacing="0" role="presentation"
       style="background:#f4f7f9; padding:30px 15px;">
    <tr>
        <td align="center">

            <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
                   style="max-width:520px; background:#ffffff; border-radius:12px; overflow:hidden;">

                <tr>
                    <td style="background:#7f9fb2; padding:24px 30px; text-align:center;">
                        <h1 style="margin:0; color:#ffffff; font-size:24px;">
                            PharmAssist
                        </h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px 36px;">

                        <h2 style="margin:0 0 18px; color:#5f7f93; font-size:22px;">
                            Verify your email
                        </h2>

                        <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                            Hello {{ $firstName }},
                        </p>

                        <p style="margin:0 0 22px; font-size:15px; line-height:1.6;">
                            Thank you for creating your PharmAssist account.
                            Please use the verification code below to verify your email address.
                        </p>

                        <div style="
                            background:#eef4f7;
                            border-radius:10px;
                            padding:22px;
                            text-align:center;
                            margin:24px 0;
                        ">
                            <div style="
                                color:#5f7f93;
                                font-size:34px;
                                font-weight:700;
                                letter-spacing:8px;
                            ">
                                {{ $code }}
                            </div>
                        </div>

                        <p style="margin:0 0 14px; font-size:14px; line-height:1.6;">
                            This code expires in <strong>{{ $minutes }} minutes</strong>.
                        </p>

                        <p style="margin:0; font-size:14px; line-height:1.6; color:#666666;">
                            If you did not create a PharmAssist account, you may safely ignore this email.
                        </p>

                    </td>
                </tr>

                <tr>
                    <td style="
                        padding:20px 30px;
                        background:#f8fafb;
                        text-align:center;
                        font-size:12px;
                        color:#8a8a8a;
                    ">
                        PharmAssist<br>
                        Your Medicine, Minutes Away
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>