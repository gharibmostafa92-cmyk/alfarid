function unwrap(message) {
  let current = message;
  for (let i = 0; i < 4 && current; i += 1) {
    current = current.ephemeralMessage?.message
      || current.viewOnceMessage?.message
      || current.viewOnceMessageV2?.message
      || current.documentWithCaptionMessage?.message
      || current;
    if (!current.ephemeralMessage && !current.viewOnceMessage && !current.viewOnceMessageV2 && !current.documentWithCaptionMessage) break;
  }
  return current;
}

export function toInbound(msg) {
  const key = msg?.key ?? {};
  const jid = key.remoteJid || '';
  if (key.fromMe || !jid) return null;
  if (jid.endsWith('@g.us') || jid === 'status@broadcast' || jid.endsWith('@newsletter')) return null;
  const content = unwrap(msg.message);
  if (!content || content.protocolMessage || content.reactionMessage) return null;
  const text = content.conversation
    || content.extendedTextMessage?.text
    || content.imageMessage?.caption
    || '';
  const quoted = content.extendedTextMessage?.contextInfo?.stanzaId
    || content.imageMessage?.contextInfo?.stanzaId
    || null;
  let media = null;
  if (content.imageMessage) media = { id: key.id, type: 'image', mime: content.imageMessage.mimetype || 'image/jpeg' };
  else if (content.audioMessage || content.pttMessage) media = { id: key.id, type: 'audio', mime: 'audio/ogg' };
  else if (content.videoMessage) media = { id: key.id, type: 'video', mime: content.videoMessage.mimetype || null };
  else if (content.documentMessage) media = { id: key.id, type: 'document', mime: content.documentMessage.mimetype || null };
  if (!text && !media) return null;
  return {
    wa_message_id: key.id,
    remote_jid: jid,
    remote_jid_alt: key.remoteJidAlt || null,
    text,
    quoted_id: quoted,
    media,
  };
}
