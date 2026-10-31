const net = require('net');
const server = net.createServer(client => {
  const target = net.connect(5137, '127.0.0.1');
  client.pipe(target);
  target.pipe(client);
  client.on('error', () => {});
  target.on('error', () => {});
});
server.listen(5173, '0.0.0.0', () => {
  console.log('Proxying port 5173 -> 5137');
});
