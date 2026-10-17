const key = 'cc_uzpQ97tRSFcXu0jvBxDKkTNhSkL7e6CdUFYqLlG1j2Y2dILd';

async function ask(model, prompt) {
  const res = await fetch('https://radoxai.com/v1/chat/completions', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: model,
      messages: [{ role: 'user', content: prompt }]
    })
  });
  const data = await res.json();
  return {
    content: data.choices?.[0]?.message?.content,
    usage: data.usage
  };
}

async function run() {
  const prompt = "How many 'r's are in the word 'strawberry'? Answer with only the number and nothing else.";
  
  console.log("Testing strawberry count:");
  for (const m of ['claude-opus-4.8', 'gpt-5.5-pro', 'gemini-3.7-flash', 'deepseek-v4-pro-max']) {
    const res = await ask(m, prompt);
    console.log(`\nModel: ${m}`);
    console.log(`Answer: ${res.content?.trim()}`);
    console.log(`Reasoning tokens: ${res.usage?.completion_tokens_details?.reasoning_tokens}`);
  }
}

run().catch(console.error);
