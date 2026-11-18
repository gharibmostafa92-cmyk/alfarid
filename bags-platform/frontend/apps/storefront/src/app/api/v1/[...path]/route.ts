import { cloudRunIdentityToken, proxyCloudRunApi } from '@/lib/core/cloud-run-api';
import * as Sentry from '@sentry/nextjs';
import { continueTrace, spanToBaggageHeader, spanToTraceHeader } from '@sentry/core';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

async function handle(request: Request, context: { params: Promise<{ path: string[] }> }) {
  const upstream = process.env.PRIVATE_CLOUD_RUN_API_ORIGIN;
  if (!upstream) return new Response(null, { status: 404 });

  const parsedHops = Number.parseInt(process.env.TRUSTED_PROXY_HOPS ?? '1', 10);
  const trustedProxyHops = Number.isInteger(parsedHops) && parsedHops >= 1 ? parsedHops : 1;

  const { path } = await context.params;
  return proxyCloudRunApi(request, path, {
    upstream,
    aiUpstream: process.env.PRIVATE_CLOUD_RUN_AI_ORIGIN,
    origins: (process.env.API_PROXY_ALLOWED_ORIGINS ?? '').split(',').map(origin => origin.trim()).filter(Boolean),
    identityToken: cloudRunIdentityToken,
    clientIpSecret: process.env.API_PROXY_CLIENT_IP_SECRET,
    trustedProxyHops,
    withTraceContext: (traceContext, send) => {
      const sendInBffSpan = () => Sentry.startSpan(
        { name: 'bff.upstream', op: 'http.client' },
        (span) => {
          const traceHeaders = new Headers({ 'sentry-trace': spanToTraceHeader(span) });
          const baggage = spanToBaggageHeader(span);
          if (baggage) traceHeaders.set('baggage', baggage);
          return send(traceHeaders);
        },
      );
      if (!traceContext) return sendInBffSpan();
      return continueTrace({
        // Ignore the public sampled flag and all caller baggage. The BFF's
        // tracesSampler makes the local decision for this request.
        sentryTrace: `${traceContext.traceId}-${traceContext.parentSpanId}`,
        baggage: '',
      }, sendInBffSpan);
    },
  });
}

export { handle as GET, handle as HEAD, handle as POST, handle as PUT, handle as PATCH, handle as DELETE, handle as OPTIONS };
