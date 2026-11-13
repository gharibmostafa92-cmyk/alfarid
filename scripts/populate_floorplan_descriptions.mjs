import fs from 'node:fs';
import path from 'node:path';

const descDir = 'floorplan/descriptions';
const files = fs.readdirSync(descDir).filter(f => f.endsWith('.json'));

function cleanTitle(s) {
  return s
    .replace(/([A-Z])/g, ' $1')
    .replace(/[_\-.]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/\b\w/g, c => c.toUpperCase());
}

function generateMeta(filePath, existingName) {
  const p = filePath.replace(/\\/g, '/');
  const base = path.basename(p);
  const ext = path.extname(p);
  const rawName = path.basename(p, ext);

  // 1. Migrations
  if (p.includes('/database/migrations/')) {
    const clean = rawName.replace(/^\d{4}_\d{2}_\d{2}_\d{6}_/, '');
    const readable = cleanTitle(clean);
    return {
      name: readable.length > 30 ? readable.slice(0, 30) : readable,
      description: `Updates database schema to support ${clean.replace(/_/g, ' ')} across store tables.`
    };
  }

  // 2. Tests
  if (p.includes('/tests/')) {
    const readable = cleanTitle(rawName.replace(/(Test|Spec)$/i, ''));
    return {
      name: `${readable} Tests`,
      description: `Automated test suite verifying that ${readable.toLowerCase()} operations work reliably and safely.`
    };
  }

  // 3. Controllers
  if (p.includes('/Controllers/')) {
    const clean = rawName.replace(/Controller$/i, '');
    const readable = cleanTitle(clean);
    return {
      name: `${readable} API`,
      description: `Processes server requests and handles business actions for ${readable.toLowerCase()}.`
    };
  }

  // 4. Form Requests & Middleware
  if (p.includes('/Requests/')) {
    const readable = cleanTitle(rawName.replace(/Request$/i, ''));
    return {
      name: `${readable} Validator`,
      description: `Validates and sanitizes input data submitted for ${readable.toLowerCase()}.`
    };
  }
  if (p.includes('/Middleware/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Guard`,
      description: `Inspects incoming requests to enforce access permissions, security rules, and caching.`
    };
  }

  // 5. Models
  if (p.includes('/app/Models/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Model`,
      description: `Defines business properties, table relationships, and scopes for ${readable.toLowerCase()} records.`
    };
  }

  // 6. AI Tools
  if (p.includes('/app/Services/Ai/')) {
    const clean = rawName.replace(/Tool$/i, '');
    const readable = cleanTitle(clean);
    return {
      name: `${readable} AI Tool`,
      description: `Executes guarded analytical tasks and safe automated actions for ${readable.toLowerCase()}.`
    };
  }

  // 7. Domain Services
  if (p.includes('/app/Services/')) {
    const clean = rawName.replace(/Service$/i, '');
    const readable = cleanTitle(clean);
    return {
      name: `${readable} Service`,
      description: `Implements core business calculations, validations, and state transitions for ${readable.toLowerCase()}.`
    };
  }

  // 8. Console Commands
  if (p.includes('/app/Console/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Command`,
      description: `Automated console command performing maintenance routines and scheduled tasks for ${readable.toLowerCase()}.`
    };
  }

  // 9. Storefront UI Components & Pages
  if (p.includes('/apps/storefront/')) {
    if (base.startsWith('page.')) {
      const parentDir = path.basename(path.dirname(p));
      const readable = cleanTitle(parentDir === 'src' ? 'Home' : parentDir);
      return {
        name: `${readable} Store Page`,
        description: `Renders customer storefront view for browsing and interacting with ${readable.toLowerCase()}.`
      };
    }
    const readable = cleanTitle(rawName);
    return {
      name: readable,
      description: `Storefront interface component providing visual layout and shopping controls for ${readable.toLowerCase()}.`
    };
  }

  // 10. POS Terminal UI
  if (p.includes('/apps/pos/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Register UI`,
      description: `Point-of-sale terminal component assisting cashiers with ringing sales, shifts, and inventory.`
    };
  }

  // 11. Backoffice Admin UI
  if (p.includes('/apps/backoffice/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Admin View`,
      description: `Management dashboard screen for tracking orders, warehouse stock, and business finances.`
    };
  }

  // 12. Frontend Packages (UI / API client)
  if (p.includes('/packages/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Helper`,
      description: `Shared library component providing reusable styles, design elements, or API communications.`
    };
  }

  // 13. Documentation
  if (p.endsWith('.md') || p.includes('/docs/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Guide`,
      description: `Technical documentation outlining architectural specs, operational rules, or audit reports.`
    };
  }

  // 14. Configs & Infra
  if (p.endsWith('.json') || p.endsWith('.yml') || p.endsWith('.yaml') || p.includes('/config/')) {
    const readable = cleanTitle(rawName);
    return {
      name: `${readable} Settings`,
      description: `Configures runtime environment options, service credentials, and server defaults.`
    };
  }

  // Default fallback
  const fallback = existingName || cleanTitle(rawName);
  return {
    name: fallback,
    description: `Provides operational logic and supporting functions for ${fallback.toLowerCase()}.`
  };
}

let updatedFilesCount = 0;
let updatedRoutesCount = 0;

for (const file of files) {
  const fullPath = path.join(descDir, file);
  const data = JSON.parse(fs.readFileSync(fullPath, 'utf8'));
  let changed = false;

  if (data.files) {
    for (const [fp, obj] of Object.entries(data.files)) {
      if (!obj.description || obj.description.trim() === '') {
        const meta = generateMeta(fp, obj.name);
        obj.name = meta.name;
        obj.description = meta.description;
        changed = true;
        updatedFilesCount++;
      }
    }
  }

  if (data.routes) {
    for (const [rp, obj] of Object.entries(data.routes)) {
      if (!obj.description || obj.description.trim() === '') {
        const [method, routePath] = rp.split(' ');
        const cleanPath = routePath.replace(/^\//, '').replace(/[\/_]/g, ' ');
        const readable = cleanTitle(cleanPath);
        obj.name = `${readable} Handler`;
        obj.description = `Processes ${method} web requests to handle ${readable.toLowerCase()} in the store platform.`;
        changed = true;
        updatedRoutesCount++;
      }
    }
  }

  if (changed) {
    fs.writeFileSync(fullPath, JSON.stringify(data, null, 2));
  }
}

console.log(`Successfully updated ${updatedFilesCount} file descriptions and ${updatedRoutesCount} route descriptions.`);
