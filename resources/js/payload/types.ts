/** Props shared with every page by App\Infrastructure\Http\Middleware\HandleInertiaRequests. */
export interface SharedData {
    name: string;
    [key: string]: unknown;
}
