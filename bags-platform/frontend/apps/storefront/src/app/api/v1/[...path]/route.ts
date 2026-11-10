import { cloudRunIdentityToken, proxyCloudRunApi } from '@/lib/core/cloud-run-api';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

async function handle(request: Request, context: { params: Promise<{ path: string[] }> }) {
  const upstream = process.env.PRIVATE_CLOUD_RUN_API_ORIGIN;
  if (!upstream) return new Response(null, { status: 404 });

  const { path } = await context.params;
  return proxyCloudRunApi(request, path, {
    upstream,
    origins: (process.env.API_PROXY_ALLOWED_ORIGINS ?? '').split(',').map(origin => origin.trim()),
    identityToken: cloudRunIdentityToken,
  });
}

export { handle as GET, handle as HEAD, handle as POST, handle as PUT, handle as PATCH, handle as DELETE, handle as OPTIONS };
