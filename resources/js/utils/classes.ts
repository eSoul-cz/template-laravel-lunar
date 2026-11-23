import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merges conditional class names and resolves conflicting Tailwind utilities (the shadcn-vue `cn` helper). */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
