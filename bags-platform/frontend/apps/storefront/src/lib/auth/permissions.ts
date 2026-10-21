export const can = (perms: string[] | undefined, p: string) => !!perms && (perms.includes('*') || perms.includes(p));
