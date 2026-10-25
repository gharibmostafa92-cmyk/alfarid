import type { Metadata } from 'next';
import { DesignShowcaseContent } from '@/components/design/DesignShowcaseContent';

export const metadata: Metadata = {
  title: '3D Interactive Bag Showcase | Sophie Atelier',
  description: 'Experience our flagship luxury bags in interactive 3D and explore fine artisan details.',
};

export default function DesignPage() {
  return <DesignShowcaseContent />;
}
