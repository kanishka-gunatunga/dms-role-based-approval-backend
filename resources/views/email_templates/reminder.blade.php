<?php
use App\Models\CompanyProfile;
$company_profile = CompanyProfile::first();
?>
<!doctype html>
<html lang="en-US">

<head>
    <style type="text/css">
        a:hover {text-decoration: underline !important;}
    </style>
</head>

<body marginheight="0" topmargin="0" marginwidth="0" style="margin: 0px; background-color: #f2f3f8;" leftmargin="0">
    <!--100% body table-->
    <table cellspacing="0" border="0" cellpadding="0" width="100%" bgcolor="#f2f3f8"
        style="@import url(https://fonts.googleapis.com/css?family=Rubik:300,400,500,700|Open+Sans:300,400,600,700); font-family: 'Open Sans', sans-serif;">
        <tr>
            <td>
                <table style="background-color: #f2f3f8; max-width:670px; margin:0 auto;" width="100%" border="0"
                    align="center" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="height:80px;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="text-align:center;">
                          <a href="#" title="logo" target="_blank">
                            <img width="250" src="{{ asset('uploads/company_profile/'.$company_profile->logo) }}" title="logo" alt="logo">
                          </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:20px;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td>
                            <table width="95%" border="0" align="center" cellpadding="0" cellspacing="0"
                                style="max-width:670px;background:#fff; border-radius:3px; text-align:center;
                                       -webkit-box-shadow:0 6px 18px 0 rgba(0,0,0,.06);
                                       -moz-box-shadow:0 6px 18px 0 rgba(0,0,0,.06);
                                       box-shadow:0 6px 18px 0 rgba(0,0,0,.06);">
                                <tr>
                                    <td style="height:40px;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 35px;">
                                        <!-- Reminder Subject -->
                                        <h1 style="color:#1e1e2d; font-weight:500; margin:0;
                                                   font-size:28px;font-family:'Rubik',sans-serif;">
                                            {{ $details['subject'] ?? 'Reminder Notification' }}
                                        </h1>

                                        <span style="display:inline-block; vertical-align:middle;
                                                     margin:20px 0; border-bottom:1px solid #cecece; width:100px;"></span>

                                        <!-- Reminder Message -->
                                        <p style="color:#455056; font-size:15px; line-height:24px; margin:0;">
                                            {!! nl2br(e($details['message'] ?? 'You have a new reminder.')) !!}
                                        </p>

                                        <!-- Optional Date & Time -->
                                        @if(!empty($details['date_time']))
                                            <p style="margin-top:15px; font-size:14px; color:#666;">
                                                <strong>Scheduled At:</strong> {{ \Carbon\Carbon::parse($details['date_time'])->format('d M Y, h:i A') }}
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td style="height:40px;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:20px;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="height:60px; text-align:center; color:#888; font-size:12px;">
                            This is an automated reminder from <strong>{{ $company_profile->company_name ?? 'Our System' }}</strong>.
                        </td>
                    </tr>
                    <tr>
                        <td style="height:40px;">&nbsp;</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <!--/100% body table-->
</body>

</html>
