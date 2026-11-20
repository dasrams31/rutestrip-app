import ChatInterface from '@/components/ChatInterface';

export const metadata = {
  title: 'RuteStrip AI – Chat Pendakian',
  description: 'Chat dengan asisten AI pendakian gunung. Tanya jalur, cuaca, itinerary, logistik, dan survival guide.',
};

/* /chat?embed=1 is the compact panel framed by rutestrip.ai.id (see next.config.mjs). */
export default async function ChatPage({ searchParams }) {
  const { embed } = await searchParams;
  return <ChatInterface embed={embed === '1'} />;
}
