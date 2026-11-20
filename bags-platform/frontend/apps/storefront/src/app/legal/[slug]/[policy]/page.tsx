import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { LegalPolicyPage } from '@/components/legal/LegalPolicyPage';
import { LEGAL_POLICY_VERSIONS, getLegalPolicyBySlug } from '@/lib/legal-policies';
import { getServerBranding } from '@/lib/core/server-branding';

type PageProps = { params: Promise<{ slug: string; policy: string }> };

export function generateStaticParams() {
  return Object.entries(LEGAL_POLICY_VERSIONS).flatMap(([version, policies]) =>
    policies.map(({ slug }) => ({ slug: version, policy: slug })),
  );
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug: version, policy: policySlug } = await params;
  const policy = getLegalPolicyBySlug(policySlug, version);
  if (!policy) return {};
  const branding = await getServerBranding();
  return {
    title: policy.title + ' | ' + branding.name,
    description: policy.summary,
    robots: { index: true, follow: true },
    other: { 'page-type': 'product', 'site-kind': 'other', 'legal-policy-version': version },
  };
}

export default async function VersionedLegalPolicyRoute({ params }: PageProps) {
  const { slug: version, policy: policySlug } = await params;
  const policy = getLegalPolicyBySlug(policySlug, version);
  if (!policy) notFound();
  return <LegalPolicyPage policyId={policy.id} policyVersion={version} />;
}
