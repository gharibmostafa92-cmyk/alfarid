import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { LegalPolicyPage } from '@/components/legal/LegalPolicyPage';
import { LEGAL_POLICIES, getLegalPolicyBySlug } from '@/lib/legal-policies';
import { getServerBranding } from '@/lib/core/server-branding';

type PageProps = { params: Promise<{ slug: string }> };

export function generateStaticParams() {
  return LEGAL_POLICIES.map(({ slug }) => ({ slug }));
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const policy = getLegalPolicyBySlug(slug);
  if (!policy) return {};
  const branding = await getServerBranding();
  return {
    title: policy.title + ' | ' + branding.name,
    description: policy.summary,
    robots: { index: true, follow: true },
    other: { 'page-type': 'product', 'site-kind': 'other' },
  };
}

export default async function LegalPolicyRoute({ params }: PageProps) {
  const { slug } = await params;
  const policy = getLegalPolicyBySlug(slug);
  if (!policy) notFound();
  return <LegalPolicyPage policyId={policy.id} />;
}
