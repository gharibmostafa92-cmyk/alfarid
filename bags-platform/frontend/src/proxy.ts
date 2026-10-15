import { NextResponse } from "next/server";

// Pass-through only: authentication and authorization remain enforced by the API.
export function proxy() {
  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
