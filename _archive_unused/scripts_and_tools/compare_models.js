const key = 'cc_uzpQ97tRSFcXu0jvBxDKkTNhSkL7e6CdUFYqLlG1j2Y2dILd';

async function send(model, prompt) {
  const res = await fetch('https://radoxai.com/v1/chat/completions', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: model,
      temperature: 0,
      messages: [{ role: 'user', content: prompt }]
    })
  });
  return await res.json();
}

async function main() {
  const prompt = "Solve this puzzle: A farmer needs to cross a river with a wolf, a goat, and a cabbage. He has a boat that can only carry himself and one item. If left alone, the wolf eats the goat, or the goat eats the cabbage. Describe the minimum steps.";
  
  const m1 = await send('claude-opus-4.8', prompt);
  const m2 = await send('gpt-5.5-pro', prompt);
  const m3 = await send('gemini-3.7-flash', prompt);

  console.log('--- claude-opus-4.8 ---');
  console.log('Tokens:', m1.usage);
  console.log('Reply start:', m1.choices?.[0]?.message?.content?.substring(0, 150));

  console.log('\n--- gpt-5.5-pro ---');
  console.log('Tokens:', m2.usage);
  console.log('Reply start:', m2.choices?.[0]?.message?.content?.substring(0, 150));

  console.log('\n--- gemini-3.7-flash ---');
  console.log('Tokens:', m3.usage);
  console.log('Reply start:', m3.choices?.[0]?.message?.content?.substring(0, 150));
}

main().catch(console.error);
