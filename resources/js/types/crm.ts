export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    from: number | null;
    to: number | null;
    total: number;
    last_page: number;
    links: PaginationLink[];
};

export type RoleOption = { id: number; name: string; description?: string | null };

export type UserRow = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    job_title: string | null;
    role_id: number | null;
    role: { id: number; name: string; slug: string } | null;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string | null;
};

export type RoleRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_system: boolean;
    users_count: number;
    permissions_count: number;
};

export type PermissionGroup = {
    key: string;
    label: string;
    icon: string;
    permissions: { key: string; label: string }[];
};

export type ClientRow = {
    id: number;
    kind: 'person' | 'company';
    name: string;
    legal_name: string | null;
    activity: string | null;
    commune: string | null;
    contact_name: string | null;
    contact_role: string | null;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
    notes_html?: string;
    is_active: boolean;
    leads_count?: number;
    proposals_count?: number;
    services_count?: number;
    invoices_count?: number;
    created_at: string;
    creator?: { id: number; name: string } | null;
};
