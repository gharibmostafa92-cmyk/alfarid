import { NextRequest, NextResponse } from 'next/server';

export async function POST(request: NextRequest) {
  try {
    const { token, expiresIn } = await request.json();

    if (!token) {
      return NextResponse.json({ error: 'Token is required' }, { status: 400 });
    }

    const response = NextResponse.json({ success: true });

    // Store token securely in httpOnly cookie
    response.cookies.set('auth_token', token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      path: '/',
      maxAge: expiresIn || 60 * 60 * 24 * 7, // 7 days default
    });

    return response;
  } catch (error) {
    return NextResponse.json({ error: 'Failed to set session' }, { status: 500 });
  }
}

export async function DELETE() {
  const response = NextResponse.json({ success: true });
  response.cookies.delete('auth_token');
  return response;
}
