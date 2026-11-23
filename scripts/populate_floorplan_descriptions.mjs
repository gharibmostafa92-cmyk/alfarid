import fs from 'node:fs';
import path from 'node:path';

const DESCRIPTIONS_DIR = 'floorplan/descriptions';
const MAX_MIGRATION_NAME_LENGTH = 30;

function cleanTitle(text) {
  return text
    .replace(/([A-Z])/g, ' $1')
    .replace(/[_\-.]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/\b\w/g, c => c.toUpperCase());
}

function normalizeSeparators(filePath) {
  return filePath.replace(/\\/g, '/');
}

function readableOf(rawName, stripPattern) {
  const stem = stripPattern ? rawName.replace(stripPattern, '') : rawName;
  return cleanTitle(stem);
}

function migrationMeta(rawName) {
  const withoutTimestamp = rawName.replace(/^\d{4}_\d{2}_\d{2}_\d{6}_/, '');
  const readable = cleanTitle(withoutTimestamp);
  return {
    name: readable.length > MAX_MIGRATION_NAME_LENGTH ? readable.slice(0, MAX_MIGRATION_NAME_LENGTH) : readable,
    description: `Updates database schema to support ${withoutTimestamp.replace(/_/g, ' ')} across store tables.`
  };
}

function testsMeta(rawName) {
  const readable = readableOf(rawName, /(Test|Spec)$/i);
  return {
    name: `${readable} Tests`,
    description: `Automated test suite verifying that ${readable.toLowerCase()} operations work reliably and safely.`
  };
}

function controllerMeta(rawName) {
  const readable = readableOf(rawName, /Controller$/i);
  return {
    name: `${readable} API`,
    description: `Processes server requests and handles business actions for ${readable.toLowerCase()}.`
  };
}

function requestMeta(rawName) {
  const readable = readableOf(rawName, /Request$/i);
  return {
    name: `${readable} Validator`,
    description: `Validates and sanitizes input data submitted for ${readable.toLowerCase()}.`
  };
}

function middlewareMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Guard`,
    description: `Inspects incoming requests to enforce access permissions, security rules, and caching.`
  };
}

function modelMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Model`,
    description: `Defines business properties, table relationships, and scopes for ${readable.toLowerCase()} records.`
  };
}

function aiToolMeta(rawName) {
  const readable = readableOf(rawName, /Tool$/i);
  return {
    name: `${readable} AI Tool`,
    description: `Executes guarded analytical tasks and safe automated actions for ${readable.toLowerCase()}.`
  };
}

function serviceMeta(rawName) {
  const readable = readableOf(rawName, /Service$/i);
  return {
    name: `${readable} Service`,
    description: `Implements core business calculations, validations, and state transitions for ${readable.toLowerCase()}.`
  };
}

function consoleMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Command`,
    description: `Automated console command performing maintenance routines and scheduled tasks for ${readable.toLowerCase()}.`
  };
}

function storefrontPageMeta(normalizedPath) {
  const parentDir = path.basename(path.dirname(normalizedPath));
  const readable = cleanTitle(parentDir === 'src' ? 'Home' : parentDir);
  return {
    name: `${readable} Store Page`,
    description: `Renders customer storefront view for browsing and interacting with ${readable.toLowerCase()}.`
  };
}

function storefrontComponentMeta(rawName) {
  const readable = cleanTitle(rawName);
  return {
    name: readable,
    description: `Storefront interface component providing visual layout and shopping controls for ${readable.toLowerCase()}.`
  };
}

function storefrontMeta(rawName, normalizedPath) {
  if (path.basename(normalizedPath).startsWith('page.')) {
    return storefrontPageMeta(normalizedPath);
  }
  return storefrontComponentMeta(rawName);
}

function posMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Register UI`,
    description: `Point-of-sale terminal component assisting cashiers with ringing sales, shifts, and inventory.`
  };
}

function backofficeMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Admin View`,
    description: `Management dashboard screen for tracking orders, warehouse stock, and business finances.`
  };
}

function packageMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Helper`,
    description: `Shared library component providing reusable styles, design elements, or API communications.`
  };
}

function docsMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Guide`,
    description: `Technical documentation outlining architectural specs, operational rules, or audit reports.`
  };
}

function configMeta(rawName) {
  const readable = readableOf(rawName, null);
  return {
    name: `${readable} Settings`,
    description: `Configures runtime environment options, service credentials, and server defaults.`
  };
}

function fallbackMeta(rawName, existingName) {
  const fallback = existingName || cleanTitle(rawName);
  return {
    name: fallback,
    description: `Provides operational logic and supporting functions for ${fallback.toLowerCase()}.`
  };
}

const FILE_RULES = [
  { matches: (p) => p.includes('/database/migrations/'), describe: (rawName) => migrationMeta(rawName) },
  { matches: (p) => p.includes('/tests/'), describe: (rawName) => testsMeta(rawName) },
  { matches: (p) => p.includes('/Controllers/'), describe: (rawName) => controllerMeta(rawName) },
  { matches: (p) => p.includes('/Requests/'), describe: (rawName) => requestMeta(rawName) },
  { matches: (p) => p.includes('/Middleware/'), describe: (rawName) => middlewareMeta(rawName) },
  { matches: (p) => p.includes('/app/Models/'), describe: (rawName) => modelMeta(rawName) },
  { matches: (p) => p.includes('/app/Services/Ai/'), describe: (rawName) => aiToolMeta(rawName) },
  { matches: (p) => p.includes('/app/Services/'), describe: (rawName) => serviceMeta(rawName) },
  { matches: (p) => p.includes('/app/Console/'), describe: (rawName) => consoleMeta(rawName) },
  { matches: (p) => p.includes('/apps/storefront/'), describe: (rawName, normalizedPath) => storefrontMeta(rawName, normalizedPath) },
  { matches: (p) => p.includes('/apps/pos/'), describe: (rawName) => posMeta(rawName) },
  { matches: (p) => p.includes('/apps/backoffice/'), describe: (rawName) => backofficeMeta(rawName) },
  { matches: (p) => p.includes('/packages/'), describe: (rawName) => packageMeta(rawName) },
  { matches: (p) => p.endsWith('.md') || p.includes('/docs/'), describe: (rawName) => docsMeta(rawName) },
  { matches: (p) => p.endsWith('.json') || p.endsWith('.yml') || p.endsWith('.yaml') || p.includes('/config/'), describe: (rawName) => configMeta(rawName) },
];

function generateMeta(filePath, existingName) {
  const normalizedPath = normalizeSeparators(filePath);
  const rawName = path.basename(normalizedPath, path.extname(normalizedPath));
  const rule = FILE_RULES.find((rule) => rule.matches(normalizedPath));
  if (rule) {
    return rule.describe(rawName, normalizedPath);
  }
  return fallbackMeta(rawName, existingName);
}

function isMissingDescription(entry) {
  return !entry.description || entry.description.trim() === '';
}

function describeRoute(routeKey) {
  const [method, routePath] = routeKey.split(' ');
  const readable = cleanTitle(routePath.replace(/^\//, '').replace(/[\/_]/g, ' '));
  return {
    name: `${readable} Handler`,
    description: `Processes ${method} web requests to handle ${readable.toLowerCase()} in the store platform.`
  };
}

function fillMissingEntries(section, describe) {
  let filledCount = 0;
  for (const [key, entry] of Object.entries(section ?? {})) {
    if (!isMissingDescription(entry)) {
      continue;
    }
    const meta = describe(key, entry.name);
    entry.name = meta.name;
    entry.description = meta.description;
    filledCount++;
  }
  return filledCount;
}

function processDescriptionFile(fileName) {
  const fullPath = path.join(DESCRIPTIONS_DIR, fileName);
  const document = JSON.parse(fs.readFileSync(fullPath, 'utf8'));
  const filesFilled = fillMissingEntries(document.files, (filePath, existingName) => generateMeta(filePath, existingName));
  const routesFilled = fillMissingEntries(document.routes, (routeKey) => describeRoute(routeKey));
  if (filesFilled + routesFilled > 0) {
    fs.writeFileSync(fullPath, JSON.stringify(document, null, 2));
  }
  return { filesFilled, routesFilled };
}

function main() {
  const files = fs.readdirSync(DESCRIPTIONS_DIR).filter((file) => file.endsWith('.json'));
  let updatedFilesCount = 0;
  let updatedRoutesCount = 0;
  for (const file of files) {
    const { filesFilled, routesFilled } = processDescriptionFile(file);
    updatedFilesCount += filesFilled;
    updatedRoutesCount += routesFilled;
  }
  console.log(`Successfully updated ${updatedFilesCount} file descriptions and ${updatedRoutesCount} route descriptions.`);
}

main();
