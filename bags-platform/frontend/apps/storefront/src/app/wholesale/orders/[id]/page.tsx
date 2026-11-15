import { redirect } from 'next/navigation';
export default async function Page({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; redirect(`/wholesale?screen=order&id=${encodeURIComponent(id)}`); }
