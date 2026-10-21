// Original sample values and labels, intentionally preserved.
export const initialState = {
  currentView: 'overview',
  darkMode: false,
  currencyIndex: 0,
  currencies: [{
    code: 'USD',
    flag: '🇺🇸',
    symbol: '$',
    rate: 1,
    balance: 622341.59
  }, {
    code: 'EUR',
    flag: '🇩🇪',
    symbol: '€',
    rate: 0.92,
    balance: 572554.26
  }, {
    code: 'GBP',
    flag: '🇬🇧',
    symbol: '£',
    rate: 0.79,
    balance: 491649.85
  }],
  gbpActive: false,
  selectedCardIdx: 0,
  cardFlipped: false,
  showCardNumber: false,
  cards: [{
    last4: '6782',
    full: '5412 7534 8910 6782',
    exp: '09/29',
    cvv: '611',
    frozen: false,
    bg: 'linear-gradient(145deg, #221f26 0%, #151318 100%)',
    controls: {
      online: true,
      contactless: true,
      atm: true,
      abroad: false
    },
    limit: 5500,
    spent: 1210.38
  }, {
    last4: '4356',
    full: '5289 4102 3398 4356',
    exp: '11/28',
    cvv: '842',
    frozen: false,
    bg: 'radial-gradient(circle at 25% 25%, #f48668 0%, #e0491f 60%, #b9330e 100%)',
    controls: {
      online: true,
      contactless: true,
      atm: false,
      abroad: true
    },
    limit: 8000,
    spent: 2450.00
  }],
  selectedTier: 'Gold',
  points: 12480,
  tierData: {
    Silver: {
      ptsToNext: '2,520 pts to Gold',
      benefits: ['1 point per $1 spent', 'Free standard virtual cards', '24/7 in-app chat support', 'No FX fees up to $1k / month'],
      note: 'Unlocked · Entry membership tier.'
    },
    Gold: {
      ptsToNext: '7,520 pts to Platinum',
      benefits: ['1.5 points per $1 spent', '2 free airport lounge visits a year', 'Priority chat support', 'No FX fees up to $5k / month'],
      note: 'This is your current tier.'
    },
    Platinum: {
      ptsToNext: 'Top tier unlocked',
      benefits: ['3 points per $1 spent', 'Unlimited airport lounge access', 'Dedicated account advisor', 'Zero FX fees on all currencies'],
      note: 'Earn 7,520 more points to unlock Platinum.'
    }
  },
  offers: [{
    id: 'flights',
    icon: 'plane',
    title: '5% back on flights',
    desc: 'Book with any airline using your Vortix card.',
    meta: 'Ends 30 Apr',
    active: true
  }, {
    id: 'groceries',
    icon: 'cart',
    title: '2x points on groceries',
    desc: 'Every grocery purchase earns double points.',
    meta: 'Ends 15 May',
    active: false
  }, {
    id: 'coffee',
    icon: 'coffee',
    title: 'Free coffee on Fridays',
    desc: 'One drink on us at partner cafés every week.',
    meta: 'Ongoing',
    active: false
  }],
  pointsHistory: [{
    title: 'Flight Ticket Booking',
    date: '16 Apr',
    pts: '+1,200',
    positive: true,
    type: 'star'
  }, {
    title: 'Grocery Purchase',
    date: '14 Apr',
    pts: '+1,004',
    positive: true,
    type: 'star'
  }, {
    title: 'Redeemed — Lounge pass',
    date: '11 Apr',
    pts: '-2,500',
    positive: false,
    type: 'gift'
  }, {
    title: 'Software License',
    date: '09 Apr',
    pts: '+477',
    positive: true,
    type: 'star'
  }],
  activities: [{
    id: 'INV_000076',
    name: 'Mobile App Purchase',
    category: 'Software',
    price: '$25,500',
    status: 'Completed',
    date: '17 Apr, 2026 03:45 PM',
    color: '#3b82f6'
  }, {
    id: 'INV_000075',
    name: 'AWS Cloud Infrastructure',
    category: 'Hosting',
    price: '$4,280',
    status: 'Completed',
    date: '15 Apr, 2026 11:20 AM',
    color: '#f97316'
  }, {
    id: 'INV_000074',
    name: 'Figma Enterprise Seat',
    category: 'Design',
    price: '$1,440',
    status: 'Completed',
    date: '12 Apr, 2026 09:15 AM',
    color: '#8b5cf6'
  }, {
    id: 'INV_000073',
    name: 'Client Retainer Settlement',
    category: 'Payout',
    price: '$18,900',
    status: 'Pending',
    date: '10 Apr, 2026 05:02 PM',
    color: '#10b981'
  }],
  pnlMonths: [{
    month: 'Jan',
    lossPct: 39,
    profitPct: 29,
    profitVal: '$14.5k',
    lossVal: '$19.5k'
  }, {
    month: 'Feb',
    lossPct: 30,
    profitPct: 45,
    profitVal: '$22.5k',
    lossVal: '$15.0k'
  }, {
    month: 'Mar',
    lossPct: 22,
    profitPct: 37,
    profitVal: '$18.5k',
    lossVal: '$11.0k'
  }, {
    month: 'Apr',
    lossPct: 34,
    profitPct: 28,
    profitVal: '$14.0k',
    lossVal: '$17.0k'
  }, {
    month: 'May',
    lossPct: 26,
    profitPct: 31,
    profitVal: '$15.5k',
    lossVal: '$13.0k'
  }, {
    month: 'Jun',
    lossPct: 42,
    profitPct: 16,
    profitVal: '$8.0k',
    lossVal: '$21.0k'
  }, {
    month: 'Jul',
    lossPct: 27,
    profitPct: 5,
    profitVal: '$2.5k',
    lossVal: '$13.5k'
  }, {
    month: 'Aug',
    lossPct: 14,
    profitPct: 0,
    profitVal: '$0.0k',
    lossVal: '$7.0k'
  }],
  chatFilter: 'all',
  activeChatId: 'support',
  chats: [{
    id: 'support',
    name: 'Vortix Support',
    time: '09:12 AM',
    status: 'Online',
    unread: 0,
    avatarBg: 'radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)',
    avatarText: 'V',
    messages: [{
      sender: 'them',
      text: 'Hi Code XR 👋 Your GBP wallet was set to inactive after 90 days without activity.',
      time: '09:12 AM'
    }, {
      sender: 'them',
      text: 'You can reactivate it from the Wallets panel on your dashboard any time.',
      time: '09:12 AM'
    }]
  }, {
    id: 'alex',
    name: 'Alex Rivera',
    time: 'Yesterday',
    status: 'Active 14m ago',
    unread: 2,
    avatarBg: 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
    avatarText: 'AR',
    messages: [{
      sender: 'them',
      text: 'Hey team, we just approved invoice INV_000076 for the Mobile App Purchase.',
      time: 'Yesterday'
    }, {
      sender: 'them',
      text: 'Let me know once the settlement hits your USD wallet!',
      time: 'Yesterday'
    }]
  }]
};
