{{--
    The frame of the error center's two e-mails. Plain, table-based HTML with inline styles, because mail programs read
    little else, and written without Markdown on purpose: a title comes from the error itself, and Markdown would turn
    [text](address) in it into a link a reader might trust. Everything printed here goes through {{ }}, which escapes it.

    Needs: $language, $dir ("ltr" or "rtl"), $brand, and the sections "heading" (the page title), "body" and "footer"; they are
    escaped when set with @section(name, text) or printed with {{ }}.
--}}
<!DOCTYPE html>
<html lang="{{ $language }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>@yield('heading')</title>
</head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background-color:#F7F3EA;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F7F3EA;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
<tr>
<td dir="{{ $dir }}" style="padding:0 4px 18px;font-family:Georgia,'Times New Roman',serif;font-size:22px;line-height:28px;color:#0B1F44;">{{ $brand }}</td>
</tr>
<tr>
<td dir="{{ $dir }}" style="padding:32px 28px;background-color:#FFFFFF;border:1px solid #E6DECB;border-top:3px solid #C9A25B;border-radius:6px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;line-height:24px;color:#0B1F44;">
@yield('body')
</td>
</tr>
<tr>
<td dir="{{ $dir }}" style="padding:18px 4px 0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:12px;line-height:19px;color:#5B6070;">@yield('footer')</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
