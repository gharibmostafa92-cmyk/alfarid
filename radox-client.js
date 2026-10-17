/**
 * Radox AI Client
 * يستخدم للاتصال المباشر بـ Radox AI عبر Node.js
 */

const API_KEY = process.env.RADOX_API_KEY || "cc_uzpQ97tRSFcXu0jvBxDKkTNhSkL7e6CdUFYqLlG1j2Y2dILd";
const BASE_URL = "https://radoxai.com/v1";

/**
 * دالة لإرسال رسالة واستقبال الرد
 * @param {string} prompt السؤال أو النص المطلوب
 * @param {string} model اسم النموذج (الافتراضي claude-opus-4.8 أو gemini-3.7-flash)
 */
async function askAI(prompt, model = "claude-opus-4.8") {
  const response = await fetch(`${BASE_URL}/chat/completions`, {
    method: "POST",
    headers: {
      "Authorization": `Bearer ${API_KEY}`,
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      model: model,
      messages: [
        { role: "system", content: "You are a helpful, brilliant AI assistant." },
        { role: "user", content: prompt }
      ]
    })
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`خطأ في الـ API (${response.status}): ${errorText}`);
  }

  const data = await response.json();
  return data.choices[0].message.content;
}

/**
 * دالة لاستقبال الرد كلمة بكلمة (Streaming)
 */
async function askAIStream(prompt, model = "gemini-3.7-flash", onChunk) {
  const response = await fetch(`${BASE_URL}/chat/completions`, {
    method: "POST",
    headers: {
      "Authorization": `Bearer ${API_KEY}`,
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      model: model,
      stream: true,
      messages: [{ role: "user", content: prompt }]
    })
  });

  if (!response.ok) {
    throw new Error(`خطأ في الـ API (${response.status})`);
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let fullText = "";

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;

    const chunk = decoder.decode(value, { stream: true });
    const lines = chunk.split("\n");

    for (const line of lines) {
      if (line.startsWith("data: ")) {
        const jsonStr = line.slice(6).trim();
        if (jsonStr === "[DONE]") return fullText;
        try {
          const parsed = JSON.parse(jsonStr);
          const delta = parsed.choices[0]?.delta?.content || "";
          if (delta) {
            fullText += delta;
            if (onChunk) onChunk(delta);
          }
        } catch {
          // خط غير مكتمل، تجاهل
        }
      }
    }
  }

  return fullText;
}

module.exports = { askAI, askAIStream, API_KEY, BASE_URL };

// تشغيل تجريبي عند تشغيل الملف من الطرفية: node radox-client.js
if (require.main === module) {
  const userPrompt = process.argv.slice(2).join(" ") || "أعطني نصيحة سريعة ومفيدة للمبرمجين اليوم.";
  console.log(`\n جاري السؤال باستخدام نموذج Gemini 3.7 Flash...\n`);
  
  askAIStream(userPrompt, "gemini-3.7-flash", (token) => {
    process.stdout.write(token);
  }).then(() => {
    console.log("\n\n انتهى الرد بنجاح!\n");
  }).catch((err) => {
    console.error("حدث خطأ:", err.message);
  });
}
