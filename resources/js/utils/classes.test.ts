import { describe, expect, it } from 'vitest';
import { cn } from './classes';

describe('cn', () => {
    it('joins class names and skips falsy values', () => {
        expect(cn('a', false && 'b', undefined, 'c')).toBe('a c');
    });

    it('lets the later Tailwind utility win', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });
});
