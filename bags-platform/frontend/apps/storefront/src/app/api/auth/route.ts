import { NextRequest, NextResponse } from 'next/server';

export async function POST(request: NextRequest) {
  if (request.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') {
    return NextResponse.json({ error: 'JSON is required' }, { status: 415 });
  }
  const origin = request.headers.get('origin');
  if ((origin && origin !== request.nextUrl.origin) || request.headers.get('sec-fetch-site') === 'cross-site') {
    return NextResponse.json({ error: 'Invalid origin' }, { status: 403 });
  }
  try {
    const { token, expiresIn } = await request.json();

    if (typeof token !== 'string' || !/^\d+\|[A-Za-z0-9]{40,128}$/.test(token)
      || (expiresIn !== undefined && (!Number.isSafeInteger(expiresIn) || expiresIn < 1 || expiresIn > 7200))) {
      return NextResponse.json({ error: 'Invalid session' }, { status: 400 });
    }

    const response = NextResponse.json({ success: true });

    // Store token securely in httpOnly cookie
    response.cookies.set('auth_token', token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      path: '/',
      maxAge: expiresIn ?? 7200,
    });

    return response;
  } catch (error) {
    return NextResponse.json({ error: 'Invalid JSON' }, { status: 400 });
  }
}

export async function DELETE() {
  const response = NextResponse.json({ success: true });
  response.cookies.delete('auth_token');
  return response;
}
