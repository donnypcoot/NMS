import {
  SystemStatus,
  User,
  Device,
  DeviceInterface,
  PingResult,
  SnmpResult,
  GenieAcsDevice,
  Alarm,
  AuditLog,
  DashboardSummary,
  Package,
  Customer,
  OntRealtimeData
} from '../types/nms';
import { FtthObject, FtthCable, TopologyTraceResult } from '../types/gis';

const API_BASE = '/api';

export interface ManagedRole {
  id: number;
  name: string;
  description: string | null;
  user_count?: number;
  permissions?: number[];
}

export interface ManagedPermission {
  id: number;
  code: string;
  description: string | null;
}


function getAuthToken(): string | null {
  return localStorage.getItem('transdata_token');
}

function getAuthHeader(): Record<string, string> {
  const token = getAuthToken();
  return token
    ? {
        Authorization: `Bearer ${token}`,
        'X-Auth-Token': token
      }
    : {};
}

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const token = getAuthToken();
  const headers = {
    'Content-Type': 'application/json',
    ...getAuthHeader(),
    ...options.headers
  };

  let url = `${API_BASE}${endpoint}`;
  if (token && !endpoint.includes('auth/login') && !endpoint.includes('auth/setup-admin')) {
    const sep = url.includes('?') ? '&' : '?';
    url = `${url}${sep}token=${encodeURIComponent(token)}`;
  }

  const response = await fetch(url, {
    ...options,
    headers
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error || `HTTP error ${response.status}`);
  }
  return data as T;
}

export const api = {
  // System Status & Diagnostic
  getSystemStatus: () => request<SystemStatus>('/system/status'),
  setupFirstAdmin: (payload: { username: string; email: string; password: string; full_name: string }) =>
    request<{ success: boolean; message: string; user: User }>('/auth/setup-admin', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  login: async (payload: { username: string; password: string }) => {
    const res = await request<{ success: boolean; token: string; user: User }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    if (res.token) {
      localStorage.setItem('transdata_token', res.token);
      try {
        document.cookie = `transdata_token=${encodeURIComponent(res.token)}; path=/; max-age=86400; SameSite=Lax`;
      } catch {}
    }
    return res;
  },
  logout: () => {
    localStorage.removeItem('transdata_token');
    try {
      document.cookie = 'transdata_token=; path=/; max-age=0; SameSite=Lax';
    } catch {}
  },
  getMe: () => request<{ success: boolean; user: User }>('/auth/me'),

  // User Management & Role Permissions
  getUsers: () =>
    request<{ success: boolean; users: User[]; roles: ManagedRole[] }>('/users'),
  createUser: (payload: {
    username: string;
    email: string;
    full_name: string;
    password: string;
    role_id: number;
    is_active: number;
  }) =>
    request<{ success: boolean; message: string; id?: number }>('/users', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  updateUser: (id: number, payload: {
    username: string;
    email: string;
    full_name: string;
    password?: string;
    role_id: number;
    is_active: number;
  }) =>
    request<{ success: boolean; message: string }>(`/users/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    }),
  deactivateUser: (id: number) =>
    request<{ success: boolean; message: string }>(`/users/${id}`, {
      method: 'DELETE'
    }),
  getRoles: () =>
    request<{ success: boolean; roles: ManagedRole[]; permissions: ManagedPermission[] }>('/roles'),
  createRole: (payload: { name: string; description: string; permissions: number[] }) =>
    request<{ success: boolean; id: number; message: string }>('/roles', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  updateRole: (id: number, payload: { name: string; description: string; permissions: number[] }) =>
    request<{ success: boolean; message: string }>(`/roles/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    }),
  deleteRole: (id: number) =>
    request<{ success: boolean; message: string }>(`/roles/${id}`, {
      method: 'DELETE'
    }),


  // Dashboard Summary
  getDashboardSummary: () => request<DashboardSummary>('/dashboard/summary'),

  // Devices
  getDevices: () => request<{ success: boolean; count: number; devices: Device[] }>('/devices'),
  getDevice: (id: number) => request<{ success: boolean; device: Device; interfaces: DeviceInterface[] }>(`/devices/${id}`),
  createDevice: (payload: Partial<Device>) =>
    request<{ success: boolean; message: string; device: Device }>('/devices', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  updateDevice: (id: number, payload: Partial<Device>) =>
    request<{ success: boolean; device: Device }>(`/devices/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    }),
  deleteDevice: (id: number) =>
    request<{ success: boolean; message: string }>(`/devices/${id}`, {
      method: 'DELETE'
    }),

  // Diagnostics: Real ICMP Ping & Net-SNMP
  testDevicePing: (id: number, count = 4) =>
    request<{ success: boolean; ping: PingResult }>(`/devices/${id}/ping`, {
      method: 'POST',
      body: JSON.stringify({ count })
    }),
  pollDeviceSnmp: (id: number) =>
    request<{ success: boolean; snmp: SnmpResult }>(`/devices/${id}/poll-snmp`, {
      method: 'POST'
    }),

  // MikroTik
// MikroTik
testMikrotik: (deviceId: number) =>
  request<{
    success: boolean;
    status: string;
    host?: string;
    port?: number;
    username?: string;
    latency_ms: number | null;
    message: string;
  }>('/mikrotik/test-connection', {
    method: 'POST',
    body: JSON.stringify({ device_id: deviceId })
  }),

  // GenieACS NBI
  getGenieAcsDevices: () =>
    request<{ success: boolean; count: number; nbi_url: string; error?: string; devices: GenieAcsDevice[] }>('/genieacs/devices'),

  // GIS FTTH
  getGisObjects: () => request<{ success: boolean; count: number; objects: FtthObject[] }>('/gis/objects'),
  saveGisObject: (payload: Partial<FtthObject>) =>
    request<{ success: boolean; object: FtthObject }>('/gis/objects', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  deleteGisObject: (id: string) =>
    request<{ success: boolean; message: string }>(`/gis/objects/${id}`, {
      method: 'DELETE'
    }),
  getGisCables: () => request<{ success: boolean; count: number; cables: FtthCable[] }>('/gis/cables'),
  saveGisCable: (payload: Partial<FtthCable>) =>
    request<{ success: boolean; cable: FtthCable }>('/gis/cables', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  deleteGisCable: (id: string) =>
    request<{ success: boolean; message: string }>(`/gis/cables/${id}`, {
      method: 'DELETE'
    }),
  traceTopologyImpact: (objectId: string) =>
    request<TopologyTraceResult>(`/gis/topology/trace/${objectId}`),

  // Alarms & Audit Logs
  getAlarms: () => request<{ success: boolean; alarms: Alarm[] }>('/alarms'),
  acknowledgeAlarm: (id: number) =>
    request<{ success: boolean; alarm: Alarm }>(`/alarms/${id}/acknowledge`, {
      method: 'POST'
    }),
  getAuditLogs: () => request<{ success: boolean; logs: AuditLog[] }>('/audit-logs'),

  // Settings
  getSettings: () => request<{ success: boolean; settings: Record<string, string> }>('/settings'),
  updateSettings: (settings: Record<string, string>) =>
    request<{ success: boolean; settings: Record<string, string> }>('/settings', {
      method: 'POST',
      body: JSON.stringify(settings)
    }),
getMikrotikRouters: () =>
  request<{
    success: boolean;
    routers: {
      id: number;
      name: string;
      ip_address: string;
      api_port: number;
      use_ssl: boolean;
    }[];
  }>('/mikrotik/routers'),

getMikrotikProfiles: (deviceId: number) =>
  request<{
    success: boolean;
    router?: {
      id: number;
      name: string;
      ip_address: string;
    };
    profiles: {
      name: string;
    }[];
  }>(`/mikrotik/profiles?device_id=${deviceId}`),

  // Service Packages (Setting Paket Layanan)
  getPackages: () => request<{ success: boolean; packages: Package[] }>('/packages'),
  createPackage: (payload: Partial<Package>) =>
    request<{ success: boolean; id: number; message: string }>('/packages', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  updatePackage: (id: number, payload: Partial<Package>) =>
    request<{ success: boolean; message: string }>(`/packages/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    }),
  deletePackage: (id: number) =>
    request<{ success: boolean; message: string }>(`/packages/${id}`, {
      method: 'DELETE'
    }),

  // Customers (Data Pelanggan)
  getCustomers: (search = '') =>
    request<{ success: boolean; count: number; customers: Customer[] }>(`/customers?search=${encodeURIComponent(search)}`),
  createCustomer: (payload: Partial<Customer>) =>
    request<{ success: boolean; id: number; customer_number: string; message: string }>('/customers', {
      method: 'POST',
      body: JSON.stringify(payload)
    }),
  updateCustomer: (id: number, payload: Partial<Customer>) =>
    request<{ success: boolean; message: string }>(`/customers/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    }),
  deleteCustomer: (id: number) =>
    request<{ success: boolean; message: string }>(`/customers/${id}`, {
      method: 'DELETE'
    }),
  isolirCustomer: (id: number) =>
    request<{ success: boolean; message: string; status: string }>(`/customers/${id}/isolir`, {
      method: 'POST'
    }),
  bukaIsolirCustomer: (id: number) =>
    request<{ success: boolean; message: string; status: string }>(`/customers/${id}/buka-isolir`, {
      method: 'POST'
    }),
  sendWaReminder: (id: number) =>
    request<{ success: boolean; message: string; phone: string; wa_url: string; message_text: string }>(
      `/customers/${id}/send-wa-reminder`,
      { method: 'POST' }
    ),

  // ONT Realtime Telemetry (GenieACS + SNMP OLT-C320 + Ping)
  getOntRealtime: (sn: string) =>
    request<OntRealtimeData & { success: boolean }>(`/ont/${encodeURIComponent(sn)}/realtime`)
};
