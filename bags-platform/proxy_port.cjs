const net = require('net');

const server = net.createServer(client => {
  const target = net.connect(5173, '127.0.0.1');
  client.pipe(target);
  target.pipe(client);
  client.on('error', () => {});
  target.on('error', () => {});
});

server.listen(5137, '0.0.0.0', () => {
  console.log('Proxying port 5137 -> 5173');
});
