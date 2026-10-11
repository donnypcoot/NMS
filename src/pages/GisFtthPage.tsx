import React, { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import {
  MapPin,
  Plus,
  Layers,
  Search,
  Maximize2,
  Minimize2,
  Trash2,
  Save,
  RotateCcw,
  RotateCw,
  AlertTriangle,
  Users,
  Compass,
  CheckCircle2,
  RefreshCw,
  Info,
  X,
  Radio,
  Wifi,
  Thermometer,
  Clock3,
  Zap,
  Activity,
  Server,
  Globe,
  Home
} from 'lucide-react';
import { api } from '../services/api';
import { FtthObject, FtthCable, FtthObjectType, CableType, TopologyTraceResult } from '../types/gis';
import { OntRealtimeData } from '../types/nms';
import { Modal } from '../components/Modal';

// Format uptime ONT (detik) agar mudah dibaca.
function formatOntUptime(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value) || value < 0) {
    return 'N/A';
  }

  const seconds = Math.floor(value);
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  if (days > 0) return `${days} hari ${hours} jam`;
  if (hours > 0) return `${hours} jam ${minutes} menit`;
  if (minutes > 0) return `${minutes} menit`;
  return `${seconds} detik`;
}

// RouterOS umumnya mengirim uptime seperti 2d03:04:05 atau 03:04:05.
// Format lain seperti 1w2d3h4m5s juga didukung.
function formatPppoeUptime(value: string | null | undefined): string {
  if (!value || !value.trim()) return 'N/A';

  const raw = value.trim();
  let seconds: number | null = null;

  const compact = raw.match(/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i);
  if (compact && compact.slice(1).some(part => part !== undefined)) {
    seconds =
      Number(compact[1] || 0) * 604800 +
      Number(compact[2] || 0) * 86400 +
      Number(compact[3] || 0) * 3600 +
      Number(compact[4] || 0) * 60 +
      Number(compact[5] || 0);
  } else {
    const withDays = raw.match(/^(\d+)d(\d{1,2}):(\d{2}):(\d{2})$/i);
    const clock = raw.match(/^(\d{1,2}):(\d{2}):(\d{2})$/);

    if (withDays) {
      seconds = Number(withDays[1]) * 86400 +
        Number(withDays[2]) * 3600 +
        Number(withDays[3]) * 60 +
        Number(withDays[4]);
    } else if (clock) {
      seconds = Number(clock[1]) * 3600 +
        Number(clock[2]) * 60 +
        Number(clock[3]);
    }
  }

  // Jika RouterOS mengembalikan format yang belum dikenal, tampilkan nilai asli.
  if (seconds === null || !Number.isFinite(seconds) || seconds < 0) return raw;

  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  if (days > 0) return `${days} hari ${hours} jam`;
  if (hours > 0) return `${hours} jam ${minutes} menit`;
  if (minutes > 0) return `${minutes} menit`;
  return `${seconds} detik`;
}

// Tile Provider Types
type MapTileType = 'GOOGLE_HYBRID' | 'GOOGLE_ROADMAP' | 'GOOGLE_TERRAIN' | 'OSM';

const TILE_PROVIDERS: Record<MapTileType, { name: string; url: string; subdomains?: string[]; attribution: string }> = {
  GOOGLE_HYBRID: {
    name: 'Google Satelit (Hybrid)',
    url: 'https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
    attribution: '&copy; Google Maps Platform'
  },
  GOOGLE_ROADMAP: {
    name: 'Google Roadmap (Jalan)',
    url: 'https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
    attribution: '&copy; Google Maps Platform'
  },
  GOOGLE_TERRAIN: {
    name: 'Google Terrain (Kontur)',
    url: 'https://mt1.google.com/vt/lyrs=p&x={x}&y={y}&z={z}',
    subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
    attribution: '&copy; Google Maps Platform'
  },
  OSM: {
    name: 'OpenStreetMap Standard',
    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '&copy; OpenStreetMap contributors'
  }
};

// History entry for Undo / Redo
interface HistoryAction {
  type: 'ADD_OBJECT' | 'DELETE_OBJECT' | 'MOVE_OBJECT' | 'ADD_CABLE' | 'DELETE_CABLE';
  object?: FtthObject;
  cable?: FtthCable;
  previousCoords?: { lat: number; lng: number };
  newCoords?: { lat: number; lng: number };
}

// Custom Marker Icons for Leaflet based on FTTH Object Types
function createCustomMarkerIcon(type: FtthObjectType, status: string): L.DivIcon {
  let bgColor = '#3b82f6';
  let symbol = '•';

  switch (type) {
    case 'OLT':
      bgColor = '#8b5cf6';
      symbol = 'OLT';
      break;
    case 'ODC':
      bgColor = '#06b6d4';
      symbol = 'ODC';
      break;
    case 'ODP':
      bgColor = '#10b981';
      symbol = 'ODP';
      break;
    case 'FAT':
      bgColor = '#14b8a6';
      symbol = 'FAT';
      break;
    case 'TIANG':
      bgColor = '#64748b';
      symbol = 'T';
      break;
    case 'HANDHOLE':
      bgColor = '#475569';
      symbol = 'HH';
      break;
    case 'PELANGGAN':
      bgColor = '#f59e0b';
      symbol = 'ONT';
      break;
    case 'JOINT_CLOSURE':
      bgColor = '#f97316';
      symbol = 'JC';
      break;
    case 'POP':
      bgColor = '#ec4899';
      symbol = 'POP';
      break;
  }

  if (status === 'FAULT' || status === 'DOWN') {
    bgColor = '#ef4444';
  }

  const html = `
    <div style="
      background-color: ${bgColor};
      color: white;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: bold;
      border: 2px solid white;
      box-shadow: 0 2px 8px rgba(0,0,0,0.6);
      cursor: grab;
      transition: transform 0.15s ease;
    ">
      ${symbol}
    </div>
  `;

  return L.divIcon({
    html,
    className: 'ftth-marker',
    iconSize: [28, 28],
    iconAnchor: [14, 14],
    popupAnchor: [0, -16]
  });
}

interface GisFtthPageProps {
  targetFocus?: { id?: string; lat?: number; lng?: number } | null;
  onClearTarget?: () => void;
}

export const GisFtthPage: React.FC<GisFtthPageProps> = ({ targetFocus, onClearTarget }) => {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<L.Map | null>(null);
  const currentTileLayerRef = useRef<L.TileLayer | null>(null);
  const markersRef = useRef<Record<string, L.Marker>>({});
  const polylineLayerGroupRef = useRef<L.LayerGroup | null>(null);
  const cableEditLayerGroupRef = useRef<L.LayerGroup | null>(null);
  const drawingPolylineRef = useRef<L.Polyline | null>(null);

  const [objects, setObjects] = useState<FtthObject[]>([]);
  const [cables, setCables] = useState<FtthCable[]>([]);
  const [loading, setLoading] = useState(true);
  const [isFullscreen, setIsFullscreen] = useState(false);

  // Active Tool Mode
  const [activeTool, setActiveTool] = useState<'PAN' | 'ADD_OBJECT' | 'DRAW_CABLE'>('PAN');
  const [pendingCoords, setPendingCoords] = useState<{ lat: number; lng: number } | null>(null);

  // Active Map Tile Layer
  const [activeTileType, setActiveTileType] = useState<MapTileType>('GOOGLE_HYBRID');

  // Cable drawing vertices
  const [drawnVertices, setDrawnVertices] = useState<Array<{ latitude: number; longitude: number }>>([]);
  const [isCableModalOpen, setIsCableModalOpen] = useState(false);

  // Selected Object or Cable / Detail Inspector
  const [selectedObject, setSelectedObject] = useState<FtthObject | null>(null);
  const [selectedCable, setSelectedCable] = useState<FtthCable | null>(null);
  const [editingCableId, setEditingCableId] = useState<string | null>(null);
  const [editCableVertices, setEditCableVertices] = useState<
    Array<{ latitude: number; longitude: number }>
  >([]);
  const [savingCableEdit, setSavingCableEdit] = useState(false);
  const [selectedEditVertexIndex, setSelectedEditVertexIndex] = useState<number | null>(null);
  const [traceResult, setTraceResult] = useState<TopologyTraceResult | null>(null);
  const [tracing, setTracing] = useState(false);

  // Realtime ONT Diagnostic Modal
  const [isOntModalOpen, setIsOntModalOpen] = useState(false);
  const [ontRealtimeLoading, setOntRealtimeLoading] = useState(false);
  const [ontRealtimeData, setOntRealtimeData] = useState<OntRealtimeData | null>(null);
  const [selectedOntSerial, setSelectedOntSerial] = useState<string>('');

  // Undo and Redo Stacks
  const [undoStack, setUndoStack] = useState<HistoryAction[]>([]);
  const [redoStack, setRedoStack] = useState<HistoryAction[]>([]);

  // Layer Visibility
  const [layerVisibility, setLayerVisibility] = useState({
    OLT: true,
    ODC: true,
    ODP: true,
    FAT: true,
    TIANG: true,
    HANDHOLE: true,
    PELANGGAN: true,
    JOINT_CLOSURE: true,
    POP: true,
    SPLITTER: true,
    CABLES: true
  });

  // Cursor coordinates
  const [cursorPos, setCursorPos] = useState({ lat: -6.2088, lng: 106.8456 });

  // Add Object Modal Form
  const [isObjectModalOpen, setIsObjectModalOpen] = useState(false);
  const [editingObjectId, setEditingObjectId] = useState<string | null>(null);

  const [newObjType, setNewObjType] = useState<FtthObjectType>('ODP');
  const [newObjCode, setNewObjCode] = useState('');
  const [newObjName, setNewObjName] = useState('');
  const [newObjSerialNumber, setNewObjSerialNumber] = useState('');
  const [newObjAddress, setNewObjAddress] = useState('');
  const [newObjCapacity, setNewObjCapacity] = useState('8');
  const [newObjParentId, setNewObjParentId] = useState('');
  const [newObjStatus, setNewObjStatus] = useState('ACTIVE');
  const [newObjNotes, setNewObjNotes] = useState('');
  const [submittingObject, setSubmittingObject] = useState(false);

  // Add Cable Modal Form
  const [cableCode, setCableCode] = useState('');
  const [cableName, setCableName] = useState('');
  const [cableType, setCableType] = useState<CableType>('DISTRIBUTION');
  const [cableCores, setCableCores] = useState('12');
  const [cableColor, setCableColor] = useState('#3b82f6');
  const [cableStartId, setCableStartId] = useState('');
  const [cableEndId, setCableEndId] = useState('');
  const [submittingCable, setSubmittingCable] = useState(false);

  // Search in Map Toolbar
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<FtthObject[]>([]);
  const [isSearchOpen, setIsSearchOpen] = useState(false);

  // Toast feedback
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  // 1. Fetch GIS Data from Backend
const loadGisData = async () => {
  try {
    setLoading(true);
    const [objRes, cableRes] = await Promise.all([
      api.getGisObjects(),
      api.getGisCables()
    ]);
    setObjects(objRes.objects || []);
    setCables(cableRes.cables || []);
  } catch (err) {
    console.error('Gagal memuat data GIS:', err);
  } finally {
    setLoading(false);
  }
};
  // Load GIS objects and cables when the page first opens
  useEffect(() => {
    void loadGisData();
  }, []);
  // 2. Initialize Leaflet Map
  useEffect(() => {
    if (!mapContainerRef.current || mapInstanceRef.current) return;

    const map = L.map(mapContainerRef.current, {
      center: [-6.2088, 106.8456],
      zoom: 14,
      zoomControl: false
    });

    L.control.zoom({ position: 'topright' }).addTo(map);

    // Initial Tile Layer: Google Hybrid Satellite
    const provider = TILE_PROVIDERS[activeTileType];
    const tileLayer = L.tileLayer(provider.url, {
      maxZoom: 20,
      subdomains: provider.subdomains || ['a', 'b', 'c'],
      attribution: provider.attribution
    }).addTo(map);

    currentTileLayerRef.current = tileLayer;
    polylineLayerGroupRef.current = L.layerGroup().addTo(map);
    cableEditLayerGroupRef.current = L.layerGroup().addTo(map);

    map.on('mousemove', (e: L.LeafletMouseEvent) => {
      setCursorPos({
        lat: Math.round(e.latlng.lat * 1000000) / 1000000,
        lng: Math.round(e.latlng.lng * 1000000) / 1000000
      });
    });

    map.on('click', (e: L.LeafletMouseEvent) => {
      const lat = e.latlng.lat;
      const lng = e.latlng.lng;

      if ((window as any).__gisActiveTool === 'ADD_OBJECT') {
        setPendingCoords({ lat, lng });
        setIsObjectModalOpen(true);
      } else if ((window as any).__gisActiveTool === 'DRAW_CABLE') {
        setDrawnVertices((prev) => [...prev, { latitude: lat, longitude: lng }]);
      }
    });

    mapInstanceRef.current = map;

    return () => {
      map.remove();
      mapInstanceRef.current = null;
    };
  }, []);

  // Switch Tile Layer (Google Hybrid, Google Roadmap, Google Terrain, OSM)
  const switchTileLayer = (tileType: MapTileType) => {
    if (!mapInstanceRef.current) return;
    const map = mapInstanceRef.current;

    if (currentTileLayerRef.current) {
      map.removeLayer(currentTileLayerRef.current);
    }

    const provider = TILE_PROVIDERS[tileType];
    const newTile = L.tileLayer(provider.url, {
      maxZoom: 20,
      subdomains: provider.subdomains || ['a', 'b', 'c'],
      attribution: provider.attribution
    }).addTo(map);

    currentTileLayerRef.current = newTile;
    setActiveTileType(tileType);
    showToast(`Layer peta dialihkan ke ${provider.name}`);
  };

  // Sync activeTool with window global for Leaflet event listener
  useEffect(() => {
    (window as any).__gisActiveTool = activeTool;
  }, [activeTool]);

  // Update Drawing Polyline on Map
  useEffect(() => {
    if (!mapInstanceRef.current) return;
    const map = mapInstanceRef.current;

    if (drawingPolylineRef.current) {
      map.removeLayer(drawingPolylineRef.current);
      drawingPolylineRef.current = null;
    }

    if (drawnVertices.length > 0) {
      const latlngs = drawnVertices.map((v) => [v.latitude, v.longitude] as [number, number]);
      drawingPolylineRef.current = L.polyline(latlngs, {
        color: '#f59e0b',
        dashArray: '6, 6',
        weight: 3
      }).addTo(map);
    }
  }, [drawnVertices]);

  // 3. Render Objects as Leaflet Markers
  useEffect(() => {
    if (!mapInstanceRef.current) return;
    const map = mapInstanceRef.current;

    // Clear existing markers
    Object.values(markersRef.current).forEach((m) => map.removeLayer(m));
    markersRef.current = {};

    objects.forEach((obj) => {
      // Check visibility filter
      if (!layerVisibility[obj.object_type]) return;

      const marker = L.marker([obj.latitude, obj.longitude], {
        icon: createCustomMarkerIcon(obj.object_type, obj.status),
        draggable: true,
        title: `${obj.code} - ${obj.name}`
      });

      // Informasi kapasitas port untuk popup peta
        const portCapacity = Math.max(0, Number(obj.port_capacity) || 0);
        const portUsed = Math.max(0, Number(obj.ports_used) || 0);
        const portAvailable = Math.max(
          0,
          Number(obj.ports_available ?? (portCapacity - portUsed)) || 0
        );
        const portUtilization = portCapacity > 0
          ? Math.min(100, (portUsed / portCapacity) * 100)
          : 0;
        const availablePercent = portCapacity > 0
          ? (portAvailable / portCapacity) * 100
          : 0;
        const capacityLabel = portCapacity <= 0
          ? 'Kapasitas belum diatur'
          : portAvailable <= 0
            ? 'PENUH'
            : availablePercent <= 30
              ? 'HAMPIR PENUH'
              : 'NORMAL';
        const capacityColor = portCapacity <= 0
          ? '#64748b'
          : portAvailable <= 0
            ? '#dc2626'
            : availablePercent <= 30
              ? '#d97706'
              : '#16a34a';

        // Popup ONT mengambil telemetri realtime; objek lain tetap menampilkan kapasitas port.
        const isOnt = obj.object_type === 'PELANGGAN';

        const popupContent = isOnt
          ? `
            <div style="font-family:sans-serif;font-size:12px;color:#0f172a;min-width:200px;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">
                <span style="font-size:10px;font-weight:bold;background:#dbeafe;padding:2px 6px;border-radius:4px;">ONT</span>
                <span style="font-size:10px;color:#64748b;">REALTIME</span>
              </div>
              <div style="font-weight:bold;font-size:13px;color:#0284c7;margin-bottom:2px;">${obj.code}</div>
              <div style="color:#334155;margin-bottom:8px;">${obj.name}</div>
              <div id="ont-realtime-${String(obj.id).replace(/[^a-zA-Z0-9_-]/g, '')}">
                <div style="border-top:1px solid #e2e8f0;padding-top:8px;color:#64748b;">
                  Mengambil status ONT dan daya optik...
                </div>
              </div>
            </div>
          `
          : `
            <div style="font-family: sans-serif; font-size: 12px; color: #0f172a; min-width: 190px;">
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                <span style="font-size: 10px; font-weight: bold; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">${obj.object_type}</span>
                <span style="font-size: 10px; font-weight: bold; color: ${obj.status === 'ACTIVE' ? '#16a34a' : '#dc2626'};">${obj.status}</span>
              </div>
              <div style="font-weight: bold; font-size: 13px; color: #0284c7; margin-bottom: 2px;">${obj.code}</div>
              <div style="color: #334155; margin-bottom: 4px;">${obj.name}</div>
              ${obj.address ? `<div style="font-size: 11px; color: #64748b; margin-bottom: 6px;">📍 ${obj.address}</div>` : ''}
              <div style="border-top: 1px solid #e2e8f0; padding-top: 6px; margin-top: 4px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                  <span style="color: #475569;">Port terpakai</span>
                  <b>${portUsed} / ${portCapacity}</b>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                  <span style="color: #475569;">Port tersedia</span>
                  <b style="color: ${capacityColor};">${portAvailable} Port</b>
                </div>
                <div style="height: 5px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin: 6px 0;">
                  <div style="height: 100%; width: ${portUtilization}%; background: ${capacityColor};"></div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 10px;">
                  <span style="color: ${capacityColor}; font-weight: bold;">${capacityLabel}</span>
                  <span style="color: #64748b;">${portUtilization.toFixed(1)}% terpakai</span>
                </div>
              </div>
            </div>
          `;

        marker.bindPopup(popupContent);

        if (isOnt) {
          marker.on('popupopen', async () => {
            const safeId = String(obj.id).replace(/[^a-zA-Z0-9_-]/g, '');
            const popup = marker.getPopup();
            const container = popup?.getElement()?.querySelector(`#ont-realtime-${safeId}`);
            if (!container) return;

            let sn = obj.serial_number?.trim() || '';
            if (!sn && obj.notes?.includes('SN:')) {
              const match = obj.notes.match(/SN:\s*([A-Za-z0-9]+)/);
              if (match) sn = match[1];
            }
            if (!sn) sn = obj.code.replace(/^ONT-/i, '').trim();

            if (!sn) {
              container.innerHTML = '<div style="border-top:1px solid #e2e8f0;padding-top:8px;color:#dc2626;">Nomor seri ONT belum diisi.</div>';
              return;
            }

            container.innerHTML = '<div style="border-top:1px solid #e2e8f0;padding-top:8px;color:#64748b;">Mengambil data realtime...</div>';

            try {
              const data = await api.getOntRealtime(sn);
              if (!marker.isPopupOpen()) return;

              const statusInfo: Record<string, { label: string; color: string }> = {
                working: { label: 'ONLINE', color: '#16a34a' },
                los: { label: 'LOS', color: '#dc2626' },
                dying_gasp: { label: 'DYING GASP', color: '#d97706' },
                offline: { label: 'OFFLINE', color: '#dc2626' },
                unknown: { label: 'UNKNOWN', color: '#64748b' }
              };
              const status = statusInfo[data.olt_status] || statusInfo.unknown;
              const rx = data.power_rx_dbm !== '' && data.power_rx_dbm !== null && data.power_rx_dbm !== undefined
                ? `${data.power_rx_dbm} dBm`
                : 'N/A';
              const tx = data.power_tx_dbm !== '' && data.power_tx_dbm !== null && data.power_tx_dbm !== undefined
                ? `${data.power_tx_dbm} dBm`
                : 'N/A';

              container.innerHTML = `
                <div style="border-top:1px solid #e2e8f0;padding-top:8px;">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="color:#475569;">Status ONT</span>
                    <b style="color:${status.color};">${status.label}</b>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="color:#475569;">RX Optik</span>
                    <b style="color:#0f172a;">${rx}</b>
                  </div>
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="color:#475569;">TX Optik</span>
                    <b style="color:#0f172a;">${tx}</b>
                  </div>
                  <div style="font-size:10px;color:#64748b;margin-top:8px;">
                    SN: ${sn.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                  </div>
                </div>
              `;
            } catch (error) {
              if (!marker.isPopupOpen()) return;
              container.innerHTML = '<div style="border-top:1px solid #e2e8f0;padding-top:8px;color:#dc2626;">Gagal mengambil data realtime ONT. Coba buka popup kembali.</div>';
              console.error('Gagal mengambil telemetri ONT:', error);
            }
          });
        }

      // Drag End (Pin movement) with Undo History
      marker.on('dragend', async () => {
        const newLatLng = marker.getLatLng();
        const prevCoords = { lat: obj.latitude, lng: obj.longitude };

        try {
          await api.saveGisObject({
            id: obj.id,
            object_type: obj.object_type,
            code: obj.code,
            name: obj.name,
            latitude: newLatLng.lat,
            longitude: newLatLng.lng,
            port_capacity: obj.port_capacity
          });

          // Record Undo Action
          setUndoStack((prev) => [
            {
              type: 'MOVE_OBJECT',
              object: obj,
              previousCoords: prevCoords,
              newCoords: { lat: newLatLng.lat, lng: newLatLng.lng }
            },
            ...prev
          ]);
          setRedoStack([]);

          // Muat ulang posisi node dan koordinat kabel dari backend
await loadGisData();

showToast(`Posisi ${obj.code} dan garis kabel berhasil diperbarui.`);
        } catch (err: any) {
          showToast('Gagal memindahkan objek: ' + err.message);
          marker.setLatLng([obj.latitude, obj.longitude]);
        }
      });

      marker.on('click', () => {
        setSelectedObject(obj);
        setTraceResult(null);
      });

      marker.addTo(map);
      markersRef.current[obj.id] = marker;
    });

    // Auto-fit bounds if objects exist and first load
    if (objects.length > 0 && map && !selectedObject) {
      const bounds = L.latLngBounds(objects.map((o) => [o.latitude, o.longitude]));
      map.fitBounds(bounds, { padding: [50, 50], maxZoom: 16 });
    }
  }, [objects, layerVisibility]);

  // 4. Render Cables on Map
  useEffect(() => {
    if (!polylineLayerGroupRef.current) return;
    const group = polylineLayerGroupRef.current;
    group.clearLayers();

    if (!layerVisibility.CABLES) return;

    cables.forEach((cable) => {
  if (!cable.vertices || cable.vertices.length < 2) return;

  const latlngs: [number, number][] = cable.vertices
    .map((v) => [
      Number(v.latitude),
      Number(v.longitude)
    ] as [number, number])
    .filter(([lat, lng]) =>
      Number.isFinite(lat) &&
      Number.isFinite(lng) &&
      lat >= -90 &&
      lat <= 90 &&
      lng >= -180 &&
      lng <= 180
    );

  if (latlngs.length < 2) return;

      const polyline = L.polyline(latlngs, {
        color: cable.color_hex || (cable.cable_type === 'DROPCORE' ? '#f59e0b' : '#3b82f6'),
        weight: cable.cable_type === 'FEEDER' ? 5 : cable.cable_type === 'DISTRIBUTION' ? 3.5 : 2.5,
        opacity: 0.9
      });

      polyline.bindPopup(`
        <div style="font-family: sans-serif; font-size: 12px; color: #0f172a; min-width: 160px;">
          <div style="font-weight: bold; color: #0284c7;">${cable.cable_code}</div>
          <div style="font-weight: 600; margin-bottom: 2px;">${cable.cable_name}</div>
          <div>Tipe: <b>${cable.cable_type}</b> (${cable.core_count} Core)</div>
          <div>Panjang: <b>${cable.calculated_length_m} m</b></div>
          <div style="color: ${cable.status === 'ACTIVE' ? '#16a34a' : '#dc2626'}; font-weight: bold;">Status: ${cable.status}</div>
        </div>
      `);

      polyline.on('click', (e: L.LeafletMouseEvent) => {
        if (editingCableId === cable.id) {
          const map = mapInstanceRef.current;
          if (!map) return;

          const clicked = map.latLngToLayerPoint(e.latlng);

          setEditCableVertices((prev) => {
            if (prev.length < 2) return prev;

            const points = prev.map((v) =>
              map.latLngToLayerPoint([v.latitude, v.longitude])
            );

            let insertAfter = 0;
            let minDistance = Infinity;

            for (let i = 0; i < points.length - 1; i++) {
              const a = points[i];
              const b = points[i + 1];
              const dx = b.x - a.x;
              const dy = b.y - a.y;
              const denominator = dx * dx + dy * dy;
              const t = denominator === 0 ? 0 : Math.max(
                0,
                Math.min(
                  1,
                  ((clicked.x - a.x) * dx + (clicked.y - a.y) * dy) /
                    denominator
                )
              );

              const px = clicked.x - (a.x + t * dx);
              const py = clicked.y - (a.y + t * dy);
              const distance = px * px + py * py;

              if (distance < minDistance) {
                minDistance = distance;
                insertAfter = i;
              }
            }

            const newVertex = {
              latitude: e.latlng.lat,
              longitude: e.latlng.lng
            };

            const next = [...prev];
            next.splice(insertAfter + 1, 0, newVertex);
            return next;
          });

          return;
        }

        setSelectedCable(cable);
        setSelectedObject(null);
        setTraceResult(null);
      });

      group.addLayer(polyline);
    });
  }, [cables, layerVisibility.CABLES, editingCableId]);

  // Handle titik belokan yang dapat digeser. Endpoint sengaja dikunci.
  useEffect(() => {
    const group = cableEditLayerGroupRef.current;
    if (!group) return;

    group.clearLayers();

    if (!editingCableId || editCableVertices.length < 3) return;

    editCableVertices.forEach((vertex, index) => {
      if (index === 0 || index === editCableVertices.length - 1) return;

      const marker = L.marker(
        [vertex.latitude, vertex.longitude],
        {
          draggable: true,
          icon: L.divIcon({
            className: 'cable-edit-vertex-icon',
            html: `<div style="width:14px;height:14px;border:3px solid #ffffff;border-radius:50%;background:${selectedEditVertexIndex === index ? '#ef4444' : '#f59e0b'};box-shadow:0 1px 6px rgba(0,0,0,.65);"></div>`,
            iconSize: [14, 14],
            iconAnchor: [7, 7]
          }),
          zIndexOffset: 1000
        }
      );

      marker.on('click', () => {
        setSelectedEditVertexIndex(index);
      });

      marker.on('dragend', (event: L.LeafletEvent) => {
        const moved = (event.target as L.Marker).getLatLng();

        setEditCableVertices((prev) =>
          prev.map((point, pointIndex) =>
            pointIndex === index
              ? { latitude: moved.lat, longitude: moved.lng }
              : point
          )
        );
      });

      group.addLayer(marker);
    });
  }, [editingCableId, editCableVertices, selectedEditVertexIndex]);

  // Target Focus when navigating from Data Pelanggan
  useEffect(() => {
    if (!targetFocus || !mapInstanceRef.current) return;
    const { id, lat, lng } = targetFocus;
    if (lat && lng) {
      mapInstanceRef.current.flyTo([lat, lng], 18, { duration: 1.5 });
      if (id) {
        const found = objects.find((o) => o.id === id || o.code === id);
        if (found) {
          setSelectedObject(found);
          setSelectedCable(null);
        }
        const marker = markersRef.current[id];
        if (marker) {
          marker.openPopup();
        }
      }
    }
  }, [targetFocus, objects]);

  // Keyboard Shortcuts for Undo & Redo (Ctrl+Z & Ctrl+Y)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        if (e.shiftKey) {
          e.preventDefault();
          handleRedo();
        } else {
          e.preventDefault();
          handleUndo();
        }
      } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        handleRedo();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [undoStack, redoStack]);

  // Search objects by name or code
  useEffect(() => {
    if (!searchQuery.trim()) {
      setSearchResults([]);
      return;
    }
    const q = searchQuery.toLowerCase().trim();
    const matches = objects.filter(
      (o) => o.code.toLowerCase().includes(q) || o.name.toLowerCase().includes(q) || (o.address && o.address.toLowerCase().includes(q))
    );
    setSearchResults(matches.slice(0, 8));
  }, [searchQuery, objects]);

  // Jump to Searched Object
  const handleSelectSearchResult = (obj: FtthObject) => {
    setSelectedObject(obj);
    setIsSearchOpen(false);
    setSearchQuery('');

    if (mapInstanceRef.current) {
      mapInstanceRef.current.flyTo([obj.latitude, obj.longitude], 18, { duration: 1.2 });
      const marker = markersRef.current[obj.id];
      if (marker) {
        marker.openPopup();
      }
    }
  };

  // Undo Handler
  const handleUndo = async () => {
    if (undoStack.length === 0) return;
    const [action, ...remainingUndo] = undoStack;

    try {
      if (action.type === 'MOVE_OBJECT' && action.object && action.previousCoords) {
        await api.saveGisObject({
          id: action.object.id,
          object_type: action.object.object_type,
          code: action.object.code,
          name: action.object.name,
          latitude: action.previousCoords.lat,
          longitude: action.previousCoords.lng
        });

        setObjects((prev) =>
          prev.map((o) =>
            o.id === action.object!.id
              ? { ...o, latitude: action.previousCoords!.lat, longitude: action.previousCoords!.lng }
              : o
          )
        );

        setRedoStack((prev) => [action, ...prev]);
        setUndoStack(remainingUndo);
        showToast(`Undo: Posisi ${action.object.code} dikembalikan.`);
      } else if (action.type === 'ADD_OBJECT' && action.object) {
        await api.deleteGisObject(action.object.id);
        setObjects((prev) => prev.filter((o) => o.id !== action.object!.id));
        setRedoStack((prev) => [action, ...prev]);
        setUndoStack(remainingUndo);
        showToast(`Undo: Objek ${action.object.code} dihapus kembali.`);
      } else if (action.type === 'DELETE_OBJECT' && action.object) {
        const res = await api.saveGisObject(action.object);
        setObjects((prev) => [...prev, res.object]);
        setRedoStack((prev) => [action, ...prev]);
        setUndoStack(remainingUndo);
        showToast(`Undo: Objek ${action.object.code} dipulihkan.`);
      } else if (action.type === 'DELETE_CABLE' && action.cable) {
        const res = await api.saveGisCable(action.cable);
        setCables((prev) => [...prev, res.cable]);
        setRedoStack((prev) => [action, ...prev]);
        setUndoStack(remainingUndo);
        showToast(`Undo: Kabel ${action.cable.cable_code} dipulihkan.`);
      }
    } catch (err: any) {
      showToast('Gagal mengeksekusi Undo: ' + err.message);
    }
  };

  // Redo Handler
  const handleRedo = async () => {
    if (redoStack.length === 0) return;
    const [action, ...remainingRedo] = redoStack;

    try {
      if (action.type === 'MOVE_OBJECT' && action.object && action.newCoords) {
        await api.saveGisObject({
          id: action.object.id,
          object_type: action.object.object_type,
          code: action.object.code,
          name: action.object.name,
          latitude: action.newCoords.lat,
          longitude: action.newCoords.lng
        });

        setObjects((prev) =>
          prev.map((o) =>
            o.id === action.object!.id
              ? { ...o, latitude: action.newCoords!.lat, longitude: action.newCoords!.lng }
              : o
          )
        );

        setUndoStack((prev) => [action, ...prev]);
        setRedoStack(remainingRedo);
        showToast(`Redo: Posisi ${action.object.code} diaplikasikan ulang.`);
      } else if (action.type === 'ADD_OBJECT' && action.object) {
        const res = await api.saveGisObject(action.object);
        setObjects((prev) => [...prev, res.object]);
        setUndoStack((prev) => [action, ...prev]);
        setRedoStack(remainingRedo);
        showToast(`Redo: Objek ${action.object.code} ditambahkan ulang.`);
      } else if (action.type === 'DELETE_OBJECT' && action.object) {
        await api.deleteGisObject(action.object.id);
        setObjects((prev) => prev.filter((o) => o.id !== action.object!.id));
        setUndoStack((prev) => [action, ...prev]);
        setRedoStack(remainingRedo);
        showToast(`Redo: Objek ${action.object.code} dihapus.`);
      } else if (action.type === 'DELETE_CABLE' && action.cable) {
        await api.deleteGisCable(action.cable.id);
        setCables((prev) => prev.filter((c) => c.id !== action.cable!.id));
        setUndoStack((prev) => [action, ...prev]);
        setRedoStack(remainingRedo);
        showToast(`Redo: Kabel ${action.cable.cable_code} dihapus.`);
      }
    } catch (err: any) {
      showToast('Gagal mengeksekusi Redo: ' + err.message);
    }
  };

  // Delete Object
  const handleDeleteSelectedObject = async () => {
    if (!selectedObject) return;

    try {
      await api.deleteGisObject(selectedObject.id);
      setUndoStack((prev) => [{ type: 'DELETE_OBJECT', object: selectedObject }, ...prev]);
      setRedoStack([]);
      setObjects((prev) => prev.filter((o) => o.id !== selectedObject.id));
      showToast(`Objek ${selectedObject.code} berhasil dihapus (dapat di-Undo).`);
      setSelectedObject(null);
    } catch (err: any) {
      showToast(err.message || 'Gagal menghapus objek');
    }
  };

    // Simpan perubahan geometri ke kabel yang sudah ada.
    const handleSaveEditedCable = async () => {
      if (!selectedCable || editingCableId !== selectedCable.id) return;

      if (
        !selectedCable.start_object_id ||
        !selectedCable.end_object_id
      ) {
        showToast(
          'Kabel ini belum memiliki node awal dan akhir. Lengkapi koneksi node sebelum menyimpan.'
        );
        return;
      }

      if (editCableVertices.length < 2) {
        showToast('Jalur kabel minimal harus memiliki dua titik.');
        return;
      }

      setSavingCableEdit(true);

      try {
        await api.saveGisCable({
          id: selectedCable.id,
          cable_code: selectedCable.cable_code,
          cable_name: selectedCable.cable_name,
          cable_type: selectedCable.cable_type,
          core_count: selectedCable.core_count,
          color_hex: selectedCable.color_hex,
          status: selectedCable.status,
          actual_installed_length_m:
            selectedCable.actual_installed_length_m,
          technician_notes: selectedCable.technician_notes,
          start_object_id: selectedCable.start_object_id,
          end_object_id: selectedCable.end_object_id,
          vertices: editCableVertices.map((v, index) => ({
            vertex_order: index + 1,
            latitude: v.latitude,
            longitude: v.longitude
          }))
        });

        const fresh = await api.getGisCables();
        const updatedCables = fresh.cables || [];
        setCables(updatedCables);

        const updatedCable = updatedCables.find(
          (c) => c.id === selectedCable.id
        );

        if (updatedCable) {
          setSelectedCable(updatedCable);
        }

        setEditingCableId(null);
        setEditCableVertices([]);
        showToast(
          `Jalur kabel ${selectedCable.cable_code} berhasil diperbarui.`
        );
      } catch (err: any) {
        showToast(
          'Gagal memperbarui jalur kabel: ' +
            (err?.message || 'Kesalahan tidak diketahui')
        );
      } finally {
        setSavingCableEdit(false);
      }
    };

  // Delete Cable
  const handleDeleteSelectedCable = async () => {
    if (!selectedCable) return;

    try {
      await api.deleteGisCable(selectedCable.id);
      setUndoStack((prev) => [{ type: 'DELETE_CABLE', cable: selectedCable }, ...prev]);
      setRedoStack([]);
      setCables((prev) => prev.filter((c) => c.id !== selectedCable.id));
      showToast(`Kabel ${selectedCable.cable_code} berhasil dihapus (dapat di-Undo).`);
      setSelectedCable(null);
    } catch (err: any) {
      showToast(err.message || 'Gagal menghapus rute kabel');
    }
  };
// Open Object Modal in Edit Mode
const handleEditObject = (obj: FtthObject) => {
  setEditingObjectId(obj.id);

  setNewObjType(obj.object_type);
  setNewObjCode(obj.code);
  setNewObjName(obj.name);
  setNewObjSerialNumber(obj.serial_number || '');
  setNewObjAddress(obj.address || '');
  setNewObjCapacity(String(obj.port_capacity ?? 0));
  setNewObjParentId(obj.parent_object_id || '');
  setNewObjStatus(obj.status || 'ACTIVE');
  setNewObjNotes(obj.notes || '');

  setPendingCoords({
    lat: Number(obj.latitude),
    lng: Number(obj.longitude)
  });

  setIsObjectModalOpen(true);
};

// Save / Update Object Handler
const handleSaveObject = async (e: React.FormEvent) => {
  e.preventDefault();

  if (!pendingCoords) return;

  if (!newObjCode.trim() || !newObjName.trim()) {
    showToast('Kode dan nama objek wajib diisi.');
    return;
  }

  setSubmittingObject(true);

  try {
    const payload: Partial<FtthObject> = {
      ...(editingObjectId ? { id: editingObjectId } : {}),
      object_type: newObjType,
      code: newObjCode.trim(),
      name: newObjName.trim(),
      serial_number: newObjType === 'PELANGGAN'
        ? (newObjSerialNumber.trim() || null)
        : null,
      latitude: pendingCoords.lat,
      longitude: pendingCoords.lng,
      address: newObjAddress.trim() || null,
      port_capacity: parseInt(newObjCapacity, 10) || 0,
      parent_object_id: newObjParentId || null,
      status: newObjStatus,
      notes: newObjNotes.trim() || null
    };

    const res = await api.saveGisObject(payload);

    if (editingObjectId) {
      // Update existing object
      const previousObject = objects.find((o) => o.id === editingObjectId);

      if (previousObject) {
        setUndoStack((prev) => [
          {
            type: 'MOVE_OBJECT',
            object: previousObject,
            previousCoords: {
              lat: Number(previousObject.latitude),
              lng: Number(previousObject.longitude)
            },
            newCoords: {
              lat: pendingCoords.lat,
              lng: pendingCoords.lng
            }
          },
          ...prev
        ]);

        setRedoStack([]);
      }


setObjects((prev) =>
  prev.map((o) =>
    o.id === editingObjectId ? res.object : o
  )
);

setSelectedObject(res.object);

// Muat ulang node dan vertices kabel dari backend.
await loadGisData();

showToast(`Objek ${res.object.code} berhasil diperbarui.`);

    } else {
      // Add new object
      setUndoStack((prev) => [
        {
          type: 'ADD_OBJECT',
          object: res.object
        },
        ...prev
      ]);

      setRedoStack([]);

      setObjects((prev) => [...prev, res.object]);

      showToast(`Objek baru ${res.object.code} berhasil dibuat.`);
    }

    setIsObjectModalOpen(false);
    setEditingObjectId(null);
    setPendingCoords(null);

    setNewObjCode('');
    setNewObjName('');
    setNewObjSerialNumber('');
    setNewObjAddress('');
    setNewObjCapacity('8');
    setNewObjParentId('');
    setNewObjStatus('ACTIVE');
    setNewObjNotes('');

    setActiveTool('PAN');

  } catch (err: any) {
    showToast(
      `${editingObjectId ? 'Gagal memperbarui' : 'Gagal menambahkan'} objek GIS: ` +
      (err.message || 'Unknown error')
    );
  } finally {
    setSubmittingObject(false);
  }
};
// Save Drawn Cable Handler
  const handleSaveCable = async (e: React.FormEvent) => {
    e.preventDefault();

    if (drawnVertices.length < 2) {
      showToast('Kabel minimal harus memiliki 2 titik koordinat.');
      return;
    }

    if (!cableStartId) {
      showToast('Titik awal (Start Object) wajib dipilih.');
      return;
    }

    if (!cableEndId) {
      showToast('Titik akhir (End Object) wajib dipilih.');
      return;
    }

    if (cableStartId === cableEndId) {
      showToast('Titik awal dan titik akhir tidak boleh menggunakan objek yang sama.');
      return;
    }

    setSubmittingCable(true);
    try {
      const res = await api.saveGisCable({
        cable_code: cableCode,
        cable_name: cableName,
        cable_type: cableType,
        core_count: parseInt(cableCores, 10) || 12,
        color_hex: cableColor,
        start_object_id: cableStartId,
        end_object_id: cableEndId,
        vertices: drawnVertices.map((v, idx) => ({
          vertex_order: idx + 1,
          latitude: v.latitude,
          longitude: v.longitude
        }))
      });

      setIsCableModalOpen(false);
      setDrawnVertices([]);
      setActiveTool('PAN');
      setCableCode('');
      setCableName('');
      loadGisData();
      showToast(`Rute kabel ${cableCode} berhasil disimpan.`);
    } catch (err: any) {
      showToast('Gagal menyimpan rute kabel: ' + err.message);
    } finally {
      setSubmittingCable(false);
    }
  };

  // Run Impact Analysis (Trace downstream subscribers)
  const handleTraceImpact = async () => {
    if (!selectedObject) return;
    setTracing(true);
    try {
      const res = await api.traceTopologyImpact(selectedObject.id);
      setTraceResult(res);
    } catch (err: any) {
      showToast(err.message || 'Gagal menjalankan analisis dampak');
    } finally {
      setTracing(false);
    }
  };

  // Open Realtime ONT Telemetry
  const handleOpenOntRealtime = async (obj: FtthObject) => {
    // Prioritaskan serial number tersimpan, lalu gunakan data lama sebagai fallback.
    let sn = obj.serial_number?.trim() || '';
    if (!sn && obj.notes && obj.notes.includes('SN:')) {
      const m = obj.notes.match(/SN:\s*([A-Za-z0-9]+)/);
      if (m) sn = m[1];
    }
    if (!sn) sn = obj.code.replace('ONT-', '').trim();
    if (!sn) {
      showToast('Nomor seri ONT belum diisi.');
      return;
    }

    setSelectedOntSerial(sn);
    setIsOntModalOpen(true);
    setOntRealtimeLoading(true);

    try {
      const data = await api.getOntRealtime(sn);
      setOntRealtimeData(data);
    } catch (err: any) {
      showToast('Gagal mengambil telemetri ONT: ' + err.message);
    } finally {
      setOntRealtimeLoading(false);
    }
  };

  return (
    <div className={`flex flex-col h-full bg-slate-950 p-4 sm:p-6 ${isFullscreen ? 'fixed inset-0 z-50 p-2' : ''}`}>
      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed top-20 right-6 z-50 flex items-center gap-3 bg-slate-900 border border-cyan-500/50 text-cyan-200 px-4 py-2.5 rounded-xl shadow-2xl shadow-cyan-950 backdrop-blur-md text-xs animate-in fade-in">
          <CheckCircle2 className="w-4 h-4 text-cyan-400 shrink-0" />
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Top Map Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3 mb-4 bg-slate-900/90 border border-slate-800 rounded-2xl p-3 shadow-lg">
        <div className="flex flex-wrap items-center gap-2">
          {/* Mode Selector */}
          <button
            onClick={() => setActiveTool('PAN')}
            className={`px-3 py-1.5 rounded-xl text-xs font-medium transition cursor-pointer flex items-center gap-1.5 ${
              activeTool === 'PAN' ? 'bg-cyan-600 text-white shadow' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
            }`}
          >
            <MapPin className="w-3.5 h-3.5" />
            <span>Navigasi</span>
          </button>

          <button
            onClick={() => {
  setEditingObjectId(null);
  setNewObjType('ODP');
  setNewObjCode('');
  setNewObjName('');
  setNewObjSerialNumber('');
  setNewObjAddress('');
  setNewObjCapacity('8');
  setNewObjParentId('');
  setNewObjStatus('ACTIVE');
  setNewObjNotes('');
  setActiveTool('ADD_OBJECT');
}}
            className={`px-3 py-1.5 rounded-xl text-xs font-medium transition cursor-pointer flex items-center gap-1.5 ${
              activeTool === 'ADD_OBJECT'
                ? 'bg-emerald-600 text-white shadow'
                : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
            }`}
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Tambah Objek FTTH</span>
          </button>

          <button
            onClick={() => setActiveTool('DRAW_CABLE')}
            className={`px-3 py-1.5 rounded-xl text-xs font-medium transition cursor-pointer flex items-center gap-1.5 ${
              activeTool === 'DRAW_CABLE'
                ? 'bg-amber-600 text-white shadow'
                : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
            }`}
          >
            <Compass className="w-3.5 h-3.5" />
            <span>Tarik Kabel ({drawnVertices.length} Titik)</span>
          </button>

          {activeTool === 'DRAW_CABLE' && drawnVertices.length >= 2 && (
            <button
              onClick={() => setIsCableModalOpen(true)}
              className="px-3 py-1.5 rounded-xl text-xs font-medium bg-cyan-600 hover:bg-cyan-500 text-white shadow transition cursor-pointer"
            >
              Simpan Rute Kabel
            </button>
          )}

          {activeTool === 'DRAW_CABLE' && drawnVertices.length > 0 && (
            <button
              onClick={() => setDrawnVertices([])}
              className="p-1.5 text-slate-400 hover:text-rose-400 rounded-xl hover:bg-slate-800"
              title="Reset Titik Jalur"
            >
              <RotateCcw className="w-4 h-4" />
            </button>
          )}

          {/* Divider */}
          <div className="h-5 w-px bg-slate-800 hidden sm:block" />

          {/* Undo and Redo Buttons */}
          <div className="flex items-center gap-1">
            <button
              onClick={handleUndo}
              disabled={undoStack.length === 0}
              className="p-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition"
              title="Undo Tindakan Terakhir (Ctrl+Z)"
            >
              <RotateCcw className="w-3.5 h-3.5" />
            </button>

            <button
              onClick={handleRedo}
              disabled={redoStack.length === 0}
              className="p-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed transition"
              title="Redo Tindakan Terakhir (Ctrl+Y)"
            >
              <RotateCw className="w-3.5 h-3.5" />
            </button>
          </div>

          {/* Delete Button for Selected Object or Cable */}
          {(selectedObject || selectedCable) && (
            <button
              onClick={selectedObject ? handleDeleteSelectedObject : handleDeleteSelectedCable}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-950/70 border border-rose-800/80 text-rose-300 hover:bg-rose-900 text-xs font-semibold transition active:scale-95 cursor-pointer shadow-sm shadow-rose-950"
              title={selectedObject ? `Hapus objek ${selectedObject.code}` : `Hapus kabel ${selectedCable?.cable_code}`}
            >
              <Trash2 className="w-3.5 h-3.5" />
              <span>{selectedObject ? `Hapus Objek (${selectedObject.code})` : `Hapus Kabel (${selectedCable?.cable_code})`}</span>
            </button>
          )}
        </div>

        {/* Right Controls: Google Maps Layer Switcher, Search, Fullscreen */}
        <div className="flex items-center gap-2">
          {/* Tile Layer Selector */}
          <div className="flex items-center gap-1 text-xs">
            <Globe className="w-3.5 h-3.5 text-cyan-400 hidden sm:block" />
            <select
              value={activeTileType}
              onChange={(e) => switchTileLayer(e.target.value as MapTileType)}
              className="bg-slate-950 border border-slate-800 rounded-xl px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-cyan-500 font-medium"
            >
              <option value="GOOGLE_HYBRID">Google Satelit (Hybrid)</option>
              <option value="GOOGLE_ROADMAP">Google Roadmap (Jalan)</option>
              <option value="GOOGLE_TERRAIN">Google Kontur (Terrain)</option>
              <option value="OSM">OpenStreetMap</option>
            </select>
          </div>

          {/* Quick Search Box */}
          <div className="relative">
            <div className="relative">
              <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                value={searchQuery}
                onFocus={() => setIsSearchOpen(true)}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setIsSearchOpen(true);
                }}
                placeholder="Cari ODP, ODC, ONT..."
                className="w-36 sm:w-48 pl-8 pr-3 py-1.5 text-xs bg-slate-950 border border-slate-800 rounded-xl text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 font-mono transition"
              />
            </div>

            {/* Search Dropdown Results */}
            {isSearchOpen && searchResults.length > 0 && (
              <div className="absolute right-0 top-full mt-1.5 w-64 bg-slate-900 border border-slate-800 rounded-xl shadow-2xl p-1 z-30 max-h-56 overflow-y-auto">
                {searchResults.map((r) => (
                  <button
                    key={r.id}
                    onClick={() => handleSelectSearchResult(r)}
                    className="w-full text-left px-3 py-2 rounded-lg hover:bg-slate-800 flex items-center justify-between text-xs transition"
                  >
                    <div>
                      <div className="font-semibold text-slate-100">{r.code}</div>
                      <div className="text-[11px] text-slate-400 truncate max-w-[150px]">{r.name}</div>
                    </div>
                    <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-cyan-400">
                      {r.object_type}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </div>

          <button
            onClick={() => setIsFullscreen(!isFullscreen)}
            className="p-2 text-slate-400 hover:text-white bg-slate-800 rounded-xl transition cursor-pointer"
            title={isFullscreen ? 'Keluar Layar Penuh' : 'Layar Penuh (Fullscreen)'}
          >
            {isFullscreen ? <Minimize2 className="w-4 h-4" /> : <Maximize2 className="w-4 h-4" />}
          </button>
        </div>
      </div>

      {/* Main Map Canvas Area */}
      <div className="relative flex-1 rounded-2xl overflow-hidden border border-slate-800 shadow-2xl">
        <div ref={mapContainerRef} className="w-full h-full z-0" />

        {/* Live Cursor Coordinate Pill */}
        <div className="absolute bottom-3 left-3 z-10 px-3 py-1 rounded-xl bg-slate-950/85 backdrop-blur-md border border-slate-800 text-[11px] font-mono text-cyan-300 pointer-events-none">
          Lat: {cursorPos.lat.toFixed(6)}, Lng: {cursorPos.lng.toFixed(6)} • Provider: {TILE_PROVIDERS[activeTileType].name}
        </div>

        {/* Map Legend Floating Box */}
        <div className="absolute top-3 left-3 z-10 p-3 rounded-2xl bg-slate-950/90 backdrop-blur-md border border-slate-800 text-[11px] text-slate-300 shadow-2xl space-y-2 max-w-[210px] hidden md:block">
          <div className="font-semibold text-slate-200 pb-1.5 border-b border-slate-800 text-xs flex items-center justify-between">
            <span>Legenda GIS FTTH</span>
            <Radio className="w-3.5 h-3.5 text-cyan-400" />
          </div>
          <div className="grid grid-cols-2 gap-x-2 gap-y-1.5">
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-purple-500"></span> OLT</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-cyan-500"></span> ODC</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> ODP</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-slate-500"></span> Tiang</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Pelanggan</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-orange-500"></span> Closure</span>
          </div>
          <div className="pt-1.5 border-t border-slate-800 text-[10px] text-slate-400">
            Geser pin untuk ubah lokasi secara presisi.
          </div>
        </div>

        {/* Selected Object Detail Inspector (Side overlay) */}
        {selectedObject && (
          <div className="absolute top-3 right-3 bottom-3 w-80 sm:w-96 z-10 p-5 rounded-2xl bg-slate-900/95 backdrop-blur-md border border-slate-800 text-xs shadow-2xl overflow-y-auto flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-cyan-950 text-cyan-300 border border-cyan-800/60 font-mono">
                    {selectedObject.object_type}
                  </span>
                  <h3 className="font-bold text-sm text-white">{selectedObject.code}</h3>
                </div>
                <button
                  onClick={() => {
                    setSelectedObject(null);
                    setTraceResult(null);
                  }}
                  className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>

              <div className="py-4 space-y-3">
                <div>
                  <span className="text-slate-400">Nama Objek:</span>
                  <div className="text-slate-200 font-semibold mt-0.5">{selectedObject.name}</div>
                </div>

                <div className="grid grid-cols-2 gap-2 bg-slate-950 p-2.5 rounded-xl border border-slate-800/80 font-mono text-[11px]">
                  <div>
                    <span className="text-slate-500">Latitude:</span>
                    <div className="text-slate-200">
  {Number.isFinite(Number(selectedObject.latitude))
    ? Number(selectedObject.latitude).toFixed(6)
    : 'N/A'}
</div>
                  </div>
                  <div>
                    <span className="text-slate-500">Longitude:</span>
                    <div className="text-slate-200">
  {Number.isFinite(Number(selectedObject.longitude))
    ? Number(selectedObject.longitude).toFixed(6)
    : 'N/A'}
</div>
                  </div>
                </div>

                {selectedObject.address && (
                  <div>
                    <span className="text-slate-400">Alamat / Patokan:</span>
                    <div className="text-slate-300 mt-0.5">{selectedObject.address}</div>
                  </div>
                )}

                {/* Special ONT Telemetry Button for Pelanggan */}
                {selectedObject.object_type === 'PELANGGAN' && (
                  <div className="p-3 bg-gradient-to-br from-amber-950/40 to-slate-950 border border-amber-800/50 rounded-xl space-y-2">
                    <div className="flex items-center gap-2 text-amber-300 font-semibold">
                      <Zap className="w-4 h-4 text-amber-400" />
                      <span>Telemetri ONT Realtime</span>
                    </div>
                    <p className="text-[11px] text-slate-400">
                      Cek power optik TX/RX, suhu, tegangan, klien wifi, dan status PON OLT ZTE C320 secara langsung.
                    </p>
                    <button
                      onClick={() => handleOpenOntRealtime(selectedObject)}
                      className="w-full py-2 px-3 rounded-lg bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white font-semibold flex items-center justify-center gap-2 transition active:scale-95 shadow-md shadow-amber-950"
                    >
                      <Activity className="w-3.5 h-3.5" />
                      <span>Buka Status ONT Realtime</span>
                    </button>
                  </div>
                )}

                {/* Port Capacity for ODP / ODC / FAT */}
                  {(selectedObject.object_type === 'ODC' || selectedObject.object_type === 'ODP' || selectedObject.object_type === 'FAT') && (() => {
                    const capacity = Math.max(0, Number(selectedObject.port_capacity) || 0);
                    const used = Math.max(0, Number(selectedObject.ports_used) || 0);
                    const available = Math.max(
                      0,
                      Number(selectedObject.ports_available ?? (capacity - used)) || 0
                    );
                    const utilization = capacity > 0
                      ? Math.min(100, (used / capacity) * 100)
                      : 0;
                    const availablePercent = capacity > 0
                      ? (available / capacity) * 100
                      : 0;
                    const capacityLabel = capacity <= 0
                      ? 'Kapasitas belum diatur'
                      : available <= 0
                        ? 'PENUH'
                        : availablePercent <= 30
                          ? 'HAMPIR PENUH'
                          : 'NORMAL';
                    const capacityColor = capacity <= 0
                      ? 'text-slate-400'
                      : available <= 0
                        ? 'text-red-400'
                        : availablePercent <= 30
                          ? 'text-amber-400'
                          : 'text-emerald-400';
                    const progressColor = capacity <= 0
                      ? 'bg-slate-600'
                      : available <= 0
                        ? 'bg-red-500'
                        : availablePercent <= 30
                          ? 'bg-amber-500'
                          : 'bg-emerald-500';

                    return (
                      <div className="p-3 bg-slate-950 rounded-xl border border-slate-800 space-y-3">
                        <div className="flex items-center justify-between gap-2">
                          <span className="text-slate-300 font-semibold">Kapasitas Port</span>
                          <span className={`text-xs font-bold ${capacityColor}`}>{capacityLabel}</span>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                          <div className="rounded-lg bg-slate-900 border border-slate-800 p-2">
                            <div className="text-[11px] text-slate-400">Port Terpakai</div>
                            <div className="text-lg font-bold font-mono text-slate-100">
                              {used}<span className="text-xs text-slate-500"> / {capacity}</span>
                            </div>
                          </div>
                          <div className="rounded-lg bg-slate-900 border border-slate-800 p-2">
                            <div className="text-[11px] text-slate-400">Port Tersedia</div>
                            <div className={`text-lg font-bold font-mono ${capacityColor}`}>
                              {available}
                            </div>
                          </div>
                        </div>
                        <div className="space-y-1">
                          <div className="flex items-center justify-between text-[11px]">
                            <span className="text-slate-400">Utilisasi</span>
                            <span className="text-slate-200 font-mono">{utilization.toFixed(1)}%</span>
                          </div>
                          <div className="w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                            <div
                              className={`h-full rounded-full transition-all ${progressColor}`}
                              style={{ width: `${utilization}%` }}
                            />
                          </div>
                        </div>
                      </div>
                    );
                  })()}
                  {/* Node Actions */}
<div className="pt-2 space-y-2">
  <button
    onClick={() => handleEditObject(selectedObject)}
    className="w-full py-2 px-3 rounded-xl bg-cyan-950/50 hover:bg-cyan-900/60 border border-cyan-800/60 text-cyan-300 font-medium flex items-center justify-center gap-2 transition cursor-pointer"
  >
    <Save className="w-4 h-4" />
    <span>Edit Node</span>
  </button>

  <button
    onClick={handleDeleteSelectedObject}
    className="w-full py-2 px-3 rounded-xl bg-rose-950/50 hover:bg-rose-900/60 border border-rose-800/60 text-rose-300 font-medium flex items-center justify-center gap-2 transition cursor-pointer"
  >
    <Trash2 className="w-4 h-4" />
    <span>Hapus Node</span>
  </button>
</div>
                {/* Trace Impact Analysis Action */}
                <div className="pt-2">
                  <button
                    onClick={handleTraceImpact}
                    disabled={tracing}
                    className="w-full py-2 px-3 rounded-xl bg-amber-950/50 hover:bg-amber-900/50 border border-amber-800/60 text-amber-300 font-medium flex items-center justify-center gap-2 transition cursor-pointer"
                  >
                    <AlertTriangle className="w-4 h-4 text-amber-400" />
                    <span>{tracing ? 'Menganalisis...' : 'Analisis Dampak Gangguan'}</span>
                  </button>
                </div>

                {/* Trace Result Output */}
                {traceResult && (
                  <div className="p-3 rounded-xl bg-slate-950 border border-amber-800/50 space-y-2 animate-in fade-in">
                    <div className="font-semibold text-amber-300 text-xs">Hasil Penelusuran Downstream:</div>
                    <div className="text-[11px] text-slate-300">
                      • Node terhubung downstream: <b>{traceResult.affected_nodes_count} node</b>
                    </div>
                    <div className="text-[11px] text-slate-300">
                      • Pelanggan terdampak: <b>{traceResult.affected_subscribers_count} pelanggan</b>
                    </div>
                  </div>
                )}
              </div>
            </div>

            <div className="pt-3 border-t border-slate-800 flex justify-between items-center">
              <span className="text-[10px] text-slate-500">Tersimpan di MariaDB</span>
              <button
                onClick={handleDeleteSelectedObject}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-rose-400 hover:text-white bg-rose-950/40 hover:bg-rose-900/60 border border-rose-800/60 rounded-xl transition cursor-pointer"
              >
                <Trash2 className="w-3.5 h-3.5" />
                <span>Hapus Node</span>
              </button>
            </div>
          </div>
        )}

        {/* Selected Cable Detail Inspector (Side overlay) */}
        {!selectedObject && selectedCable && (
          <div className="absolute top-3 right-3 bottom-3 w-80 sm:w-96 z-10 p-5 rounded-2xl bg-slate-900/95 backdrop-blur-md border border-slate-800 text-xs shadow-2xl overflow-y-auto flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-950 text-amber-300 border border-amber-800/60 font-mono">
                    {selectedCable.cable_type}
                  </span>
                  <h3 className="font-bold text-sm text-white">{selectedCable.cable_code}</h3>
                </div>
                <button
                  onClick={() => setSelectedCable(null)}
                  className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>

              <div className="py-4 space-y-3">
                <div>
                  <span className="text-slate-400">Nama Kabel Fiber:</span>
                  <div className="text-slate-200 font-semibold mt-0.5">{selectedCable.cable_name}</div>
                </div>

                <div className="grid grid-cols-2 gap-2 bg-slate-950 p-2.5 rounded-xl border border-slate-800/80 font-mono text-[11px]">
                  <div>
                    <span className="text-slate-500">Jumlah Core:</span>
                    <div className="text-slate-200 font-bold">{selectedCable.core_count} Core</div>
                  </div>
                  <div>
                    <span className="text-slate-500">Panjang Kabel:</span>
                    <div className="text-cyan-400 font-bold">{selectedCable.calculated_length_m} meter</div>
                  </div>
                </div>

                <div>
                  <span className="text-slate-400">Status Saluran:</span>
                  <div className="mt-0.5 flex items-center gap-2">
                    <span
                      className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold ${
                        selectedCable.status === 'ACTIVE'
                          ? 'bg-emerald-950/80 text-emerald-300 border border-emerald-800/80'
                          : 'bg-rose-950/80 text-rose-300 border border-rose-800/80'
                      }`}
                    >
                      <span className={`w-1.5 h-1.5 rounded-full ${selectedCable.status === 'ACTIVE' ? 'bg-emerald-400' : 'bg-rose-400'}`} />
                      {selectedCable.status}
                    </span>
                  </div>
                </div>

                <div className="p-3 bg-slate-950/80 rounded-xl border border-slate-800 text-[11px] text-slate-400 space-y-1.5">
                  <div className="font-semibold text-slate-300">Detail Multi-Segment Vertices:</div>
                  <div>• Jumlah Titik Jalur: <b>{selectedCable.vertices?.length || 0} Titik</b></div>
                  <div className="text-[10px] text-slate-500">Kabel digambar mengikuti rute tarikan tiang ODP dan drop wire rumah pelanggan.</div>
                </div>
              </div>
            </div>

            <div className="pt-3 border-t border-slate-800 space-y-3">
              {editingCableId === selectedCable.id ? (
                <>
                  <div className="rounded-lg border border-amber-700/60 bg-amber-950/40 p-3 text-[11px] text-amber-200">
                    <div className="font-semibold mb-1">
                      Mode Edit Jalur Aktif
                    </div>
                    Geser titik kuning untuk mengubah belokan. Klik garis
                    kabel untuk menambahkan titik baru. Titik awal dan akhir
                    tetap terikat ke node.
                    <div className="mt-1 text-amber-300">
                      {editCableVertices.length} titik jalur
                    </div>
                  </div>

                  <div className="rounded-lg border border-slate-700 bg-slate-900/70 p-3 space-y-2">
                    <div className="text-[11px] text-slate-300">
                      {selectedEditVertexIndex !== null &&
                       selectedEditVertexIndex > 0 &&
                       selectedEditVertexIndex < editCableVertices.length - 1
                        ? `Titik ${selectedEditVertexIndex + 1} dipilih.`
                        : 'Klik titik kuning pada peta untuk memilih titik yang akan dihapus.'}
                    </div>
                    <button
                      onClick={() => {
                        if (
                          selectedEditVertexIndex === null ||
                          selectedEditVertexIndex <= 0 ||
                          selectedEditVertexIndex >= editCableVertices.length - 1
                        ) {
                          showToast('Pilih titik belokan di antara titik awal dan akhir.');
                          return;
                        }
                        setEditCableVertices((prev) =>
                          prev.filter((_, index) => index !== selectedEditVertexIndex)
                        );
                        setSelectedEditVertexIndex(null);
                        showToast('Titik dihapus dari rancangan. Klik Simpan Perubahan untuk menyimpan.');
                      }}
                      disabled={
                        savingCableEdit ||
                        selectedEditVertexIndex === null ||
                        selectedEditVertexIndex <= 0 ||
                        selectedEditVertexIndex >= editCableVertices.length - 1
                      }
                      className="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-rose-200 bg-rose-950/60 hover:bg-rose-900 border border-rose-800/80 rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                      Hapus Titik Terpilih
                    </button>
                  </div>

                  <div className="flex gap-2">
                    <button
                      onClick={handleSaveEditedCable}
                      disabled={savingCableEdit}
                      className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-white bg-emerald-700 hover:bg-emerald-600 disabled:opacity-50 rounded-xl transition"
                    >
                      <Save className="w-3.5 h-3.5" />
                      {savingCableEdit ? 'Menyimpan...' : 'Simpan Perubahan'}
                    </button>
                    <button
                      onClick={() => {
                        setEditingCableId(null);
                        setEditCableVertices([]);
                        setSelectedEditVertexIndex(null);
                        showToast('Edit jalur dibatalkan.');
                      }}
                      disabled={savingCableEdit}
                      className="inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-200 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 rounded-xl transition"
                    >
                      <X className="w-3.5 h-3.5" />
                      Batal
                    </button>
                  </div>
                </>
              ) : (
                <button
                  onClick={() => {
                    setEditCableVertices(
                      (selectedCable.vertices || []).map((v) => ({
                        latitude: Number(v.latitude),
                        longitude: Number(v.longitude)
                      }))
                    );
                    setEditingCableId(selectedCable.id);
                    setSelectedEditVertexIndex(null);
                    setActiveTool('PAN');
                    showToast(
                      'Mode edit aktif. Geser titik kuning atau klik garis untuk menambah titik.'
                    );
                  }}
                  className="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-amber-200 hover:text-white bg-amber-950/60 hover:bg-amber-900 border border-amber-800/80 rounded-xl transition"
                >
                  <MapPin className="w-3.5 h-3.5" />
                  Edit Jalur Kabel
                </button>
              )}

              <div className="flex justify-between items-center">
                <span className="text-[10px] text-slate-500">
                  Tersimpan di Database
                </span>
                <button
                  onClick={handleDeleteSelectedCable}
                  disabled={editingCableId === selectedCable.id || savingCableEdit}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-rose-300 hover:text-white bg-rose-950/60 hover:bg-rose-900 border border-rose-800/80 rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  <span>Hapus Rute Kabel</span>
                </button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* ======================================================== */}
      {/* MODAL: ONT REALTIME TELEMETRY (GENIEACS + SNMP + PING)  */}
      {/* ======================================================== */}
      <Modal
        isOpen={isOntModalOpen}
        onClose={() => setIsOntModalOpen(false)}
        title={`Status Realtime ONT - ${selectedOntSerial}`}
        maxWidth="2xl"
      >
        {ontRealtimeLoading ? (
          <div className="py-12 flex flex-col items-center justify-center gap-3 text-slate-400">
            <RefreshCw className="w-8 h-8 animate-spin text-cyan-400" />
            <span className="text-xs">Mengambil telemetri aktual dari GenieACS, SNMP OLT C320, dan Ping ICMP...</span>
          </div>
        ) : ontRealtimeData ? (
          <div className="space-y-4">
            {/* Top Status Banner */}
            <div className="p-3.5 rounded-2xl bg-slate-950 border border-slate-800 flex items-center justify-between">
              <div>
                <div className="flex items-center gap-1.5 text-xs text-slate-400">
                  <span>Model & Vendor Perangkat</span>
                  <span className="px-1.5 py-0.2 rounded bg-cyan-950 text-cyan-400 text-[10px] font-mono border border-cyan-800/50">GenieACS</span>
                </div>
                <div className="text-base font-bold text-white flex items-center gap-2 mt-0.5">
                  <span>{ontRealtimeData.vendor} {ontRealtimeData.model}</span>
                  <span className="text-xs font-normal text-cyan-400 font-mono">({ontRealtimeData.serial_number})</span>
                </div>
              </div>

              <div className="text-right">
                <div className="flex items-center gap-1.5 text-xs text-slate-400 justify-end">
                  <span>Status Aktif</span>
                  <span className="px-1.5 py-0.2 rounded bg-purple-950 text-purple-300 text-[10px] font-mono border border-purple-800/50">SNMP OLT-C320</span>
                </div>
                <div className="flex items-center gap-1.5 mt-0.5 justify-end">
                  <span
                    className={`w-2 h-2 rounded-full ${
                      ontRealtimeData.olt_status === 'working'
                          ? 'bg-emerald-400 animate-pulse'
                          : ['los', 'dying_gasp', 'offline'].includes(ontRealtimeData.olt_status)
                            ? 'bg-rose-400'
                            : 'bg-slate-400'
                    }`}
                  />
                  <span
                    className={`text-xs font-bold uppercase ${
                      ontRealtimeData.olt_status === 'working'
                          ? 'text-emerald-400'
                          : ['los', 'dying_gasp', 'offline'].includes(ontRealtimeData.olt_status)
                            ? 'text-rose-400'
                            : 'text-slate-400'
                    }`}
                  >
                    {ontRealtimeData.olt_status === 'unknown'
                        ? 'Status belum diketahui'
                        : ontRealtimeData.olt_status === 'working'
                          ? 'Working / Normal'
                          : ontRealtimeData.olt_status.replace('_', ' ')}
                  </span>
                </div>
              </div>
            </div>

            {/* Hardware and Firmware Version */}
            <div className="grid grid-cols-2 gap-3">
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="text-[11px] text-slate-400 mb-1">Hardware Version</div>
                <div className="text-sm font-semibold text-slate-100 font-mono break-words">
                  {ontRealtimeData.hardware_version || 'N/A'}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="text-[11px] text-slate-400 mb-1">Software / Firmware Version</div>
                <div className="text-sm font-semibold text-slate-100 font-mono break-words">
                  {ontRealtimeData.software_version || 'N/A'}
                </div>
              </div>
            </div>

            {/* Optical Power Gauge Meter */}
            <div className="p-4 rounded-2xl bg-gradient-to-r from-slate-950 to-slate-900 border border-slate-800">
              <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-2">
                  <span className="text-xs font-semibold text-slate-200">Power Optik RX / TX</span>
                  <span className="px-1.5 py-0.2 rounded bg-cyan-950 text-cyan-400 text-[10px] font-mono border border-cyan-800/50">GenieACS</span>
                </div>
                <span className="text-[11px] text-cyan-400 font-medium">Standar Ideal: -15 dBm s/d -24 dBm</span>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="p-3 rounded-xl bg-slate-900 border border-slate-800/80">
                  <div className="text-[11px] text-slate-400 flex items-center justify-between">
                    <span>Power RX (Penerimaan)</span>
                    <span className="text-[10px] text-cyan-400 font-mono">GenieACS</span>
                  </div>
                  <div className="text-2xl font-bold font-mono text-emerald-400 mt-1">
                    {ontRealtimeData.power_rx_dbm} <span className="text-xs font-normal text-slate-400">{ontRealtimeData.power_rx_dbm !== 'N/A' ? 'dBm' : ''}</span>
                  </div>
                  <div className="text-[10px] text-slate-400 mt-1">Status Redaman: {
                      typeof ontRealtimeData.power_rx_dbm === 'number' && Number.isFinite(ontRealtimeData.power_rx_dbm)
                        ? ontRealtimeData.power_rx_dbm >= -24 && ontRealtimeData.power_rx_dbm <= -15
                          ? 'Dalam rentang ideal'
                          : 'Di luar rentang ideal'
                        : 'Data tidak tersedia'
                    }</div>
                </div>

                <div className="p-3 rounded-xl bg-slate-900 border border-slate-800/80">
                  <div className="text-[11px] text-slate-400 flex items-center justify-between">
                    <span>Power TX (Pengiriman)</span>
                    <span className="text-[10px] text-cyan-400 font-mono">GenieACS</span>
                  </div>
                  <div className="text-2xl font-bold font-mono text-cyan-400 mt-1">
                    {ontRealtimeData.power_tx_dbm} <span className="text-xs font-normal text-slate-400">{ontRealtimeData.power_tx_dbm !== 'N/A' ? 'dBm' : ''}</span>
                  </div>
                  <div className="text-[10px] text-slate-400 mt-1">Output Laser: {
                      typeof ontRealtimeData.power_tx_dbm === 'number' && Number.isFinite(ontRealtimeData.power_tx_dbm)
                        ? 'Data terukur'
                        : 'Data tidak tersedia'
                    }</div>
                </div>
              </div>
            </div>

            {/* Diagnostic Grid */}
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              {/* Temperature */}
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="flex items-center justify-between text-[11px] text-slate-400 mb-1">
                  <div className="flex items-center gap-1.5">
                    <Thermometer className="w-3.5 h-3.5 text-amber-400" />
                    <span>Suhu ONT</span>
                  </div>
                  <span className="text-[9px] text-cyan-400 font-mono">GenieACS</span>
                </div>
                <div className="text-lg font-bold text-white font-mono">{ontRealtimeData.temperature_c}{ontRealtimeData.temperature_c !== 'N/A' ? ' °C' : ''}</div>
                <div className="text-[10px] text-slate-500 mt-0.5">Batas aman: &lt; 65 °C</div>
              </div>

              {/* Uptime ONT */}
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="flex items-center justify-between text-[11px] text-slate-400 mb-1">
                  <div className="flex items-center gap-1.5">
                    <Clock3 className="w-3.5 h-3.5 text-yellow-400" />
                    <span>Uptime ONT</span>
                  </div>
                  <span className="text-[9px] text-cyan-400 font-mono">GenieACS</span>
                </div>
                <div className="text-lg font-bold text-white font-mono">
                  {formatOntUptime(ontRealtimeData.uptime_ont)}
                </div>
                <div className="text-[10px] text-slate-500 mt-0.5">Sejak ONT terakhir menyala ulang</div>
              </div>

              {/* Ping Latency */}
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="flex items-center justify-between text-[11px] text-slate-400 mb-1">
                  <div className="flex items-center gap-1.5">
                    <Activity className="w-3.5 h-3.5 text-emerald-400" />
                    <span>Latency / Ping</span>
                  </div>
                  <span className="text-[9px] text-emerald-400 font-mono">Ping</span>
                </div>
                <div className="text-lg font-bold text-emerald-400 font-mono">
                  {ontRealtimeData.ping_latency_ms !== null ? `${ontRealtimeData.ping_latency_ms} ms` : 'N/A'}
                </div>
                <div className="text-[10px] text-slate-500 mt-0.5">Packet Loss: {ontRealtimeData.ping_packet_loss_pct !== null ? `${ontRealtimeData.ping_packet_loss_pct}%` : 'N/A'}</div>
              </div>

              {/* WiFi SSID */}
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="flex items-center justify-between text-[11px] text-slate-400 mb-1">
                  <div className="flex items-center gap-1.5">
                    <Wifi className="w-3.5 h-3.5 text-cyan-400" />
                    <span>WiFi SSID</span>
                  </div>
                  <span className="text-[9px] text-cyan-400 font-mono">GenieACS</span>
                </div>
                <div className="text-xs font-semibold text-white truncate" title={ontRealtimeData.wifi_ssid}>
                  {ontRealtimeData.wifi_ssid}
                </div>
                <div className="text-[10px] text-cyan-400 mt-0.5 font-medium">{ontRealtimeData.wifi_active_clients === 'N/A' ? 'Jumlah klien: N/A' : `${ontRealtimeData.wifi_active_clients} Klien Aktif`}</div>
              </div>

              {/* Uptime PPPoE */}
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="flex items-center justify-between text-[11px] text-slate-400 mb-1">
                  <div className="flex items-center gap-1.5">
                    <Clock3 className="w-3.5 h-3.5 text-purple-400" />
                    <span>Uptime PPPoE</span>
                  </div>
                  <span className="text-[9px] text-purple-400 font-mono">MikroTik API</span>
                </div>
                <div className="text-xs font-mono font-semibold text-purple-300">
                  {formatPppoeUptime(ontRealtimeData.uptime_pppoe)}
                </div>
                <div className="text-[10px] text-slate-500 mt-0.5">Durasi sesi PPPoE aktif</div>
              </div>

              {/* IP & MAC */}
              <div className="p-3 rounded-xl bg-slate-950 border border-slate-800">
                <div className="flex items-center justify-between text-[11px] text-slate-400 mb-1">
                  <div className="flex items-center gap-1.5">
                    <Globe className="w-3.5 h-3.5 text-blue-400" />
                    <span>IP & MAC Address</span>
                  </div>
                  <span className="text-[9px] text-cyan-400 font-mono">GenieACS</span>
                </div>
                <div className="text-xs font-mono font-semibold text-white">{ontRealtimeData.ip_address || 'N/A'}</div>
                <div className="text-[10px] font-mono text-slate-500 truncate">{ontRealtimeData.mac_address || 'N/A'}</div>
              </div>
            </div>

            <div className="flex items-center justify-between pt-3 border-t border-slate-800 text-xs">
              <span className="text-slate-500 text-[11px]">
                Inform Terakhir: {ontRealtimeData.last_inform && !Number.isNaN(Date.parse(ontRealtimeData.last_inform))
                    ? new Date(ontRealtimeData.last_inform).toLocaleString('id-ID')
                    : 'N/A'}
              </span>
              <button
                onClick={() => selectedObject && handleOpenOntRealtime(selectedObject)}
                className="px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white font-semibold text-xs transition flex items-center gap-1.5"
              >
                <RefreshCw className="w-3.5 h-3.5" />
                <span>Refresh Telemetri</span>
              </button>
            </div>
          </div>
        ) : null}
      </Modal>

      {/* Modal: Tambah Objek GIS */}
      <Modal
  isOpen={isObjectModalOpen}
  onClose={() => {
    setIsObjectModalOpen(false);
    setEditingObjectId(null);
  }}
  title={editingObjectId ? 'Edit Node Jaringan FTTH' : 'Tambah Objek Jaringan FTTH'}
>
        <form onSubmit={handleSaveObject} className="space-y-4">
          <div className="grid grid-cols-2 gap-3 p-3 bg-slate-950 rounded-xl border border-slate-800 font-mono text-xs">
            <div>
              <span className="text-slate-500">Latitude:</span>
              <div className="text-cyan-400">{pendingCoords?.lat.toFixed(6)}</div>
            </div>
            <div>
              <span className="text-slate-500">Longitude:</span>
              <div className="text-cyan-400">{pendingCoords?.lng.toFixed(6)}</div>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Tipe Objek *</label>
              <select
                value={newObjType}
                onChange={(e) => setNewObjType(e.target.value as FtthObjectType)}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              >
                <option value="ODP">ODP (Optical Distribution Point)</option>
                <option value="ODC">ODC (Optical Distribution Cabinet)</option>
                <option value="OLT">OLT (Optical Line Terminal)</option>
                <option value="FAT">FAT (Fiber Access Terminal)</option>
                <option value="TIANG">Tiang Fiber</option>
                <option value="PELANGGAN">Pelanggan / Rumah ONT</option>
                <option value="HANDHOLE">Handhole / Manhole</option>
                <option value="JOINT_CLOSURE">Joint Closure / Splice</option>
                <option value="POP">POP / Kantor</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Kode Identitas Objek *</label>
              <input
                type="text"
                required
                value={newObjCode}
                onChange={(e) => setNewObjCode(e.target.value)}
                placeholder="cth. ODP-TDA-01"
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 uppercase font-mono focus:outline-none focus:border-cyan-500"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">Nama Deskriptif *</label>
            <input
              type="text"
              required
              value={newObjName}
              onChange={(e) => setNewObjName(e.target.value)}
              placeholder="cth. ODP Tiang Depan Perumahan Melati"
              className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
            />
          </div>
          {newObjType === 'PELANGGAN' && (
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Nomor Seri ONT
              </label>
              <input
                type="text"
                value={newObjSerialNumber}
                onChange={(e) => setNewObjSerialNumber(e.target.value.trimStart())}
                placeholder="Masukkan serial number ONT"
                maxLength={80}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-500 font-mono focus:outline-none focus:border-cyan-500"
              />
              <p className="text-[11px] text-slate-500 mt-1">
                Digunakan untuk mengambil telemetri ONT dari GenieACS.
              </p>
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Kapasitas Port</label>
              <input
                type="number"
                value={newObjCapacity}
                onChange={(e) => setNewObjCapacity(e.target.value)}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Node Induk (Parent Object)</label>
              <select
                value={newObjParentId}
                onChange={(e) => setNewObjParentId(e.target.value)}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              >
                <option value="">Tidak ada (Root / Standalone)</option>
                {objects
                  .filter((o) => o.object_type === 'ODC' || o.object_type === 'OLT' || o.object_type === 'ODP')
                  .map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.code} - {p.name}
                    </option>
                  ))}
              </select>
            </div>
          </div>
	  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
  <div>
    <label className="block text-xs font-semibold text-slate-300 mb-1">
      Status
    </label>

    <select
      value={newObjStatus}
      onChange={(e) => setNewObjStatus(e.target.value)}
      className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
    >
      <option value="ACTIVE">ACTIVE</option>
      <option value="DOWN">DOWN</option>
      <option value="FAULT">FAULT</option>
      <option value="INACTIVE">INACTIVE</option>
    </select>
  </div>

  <div>
    <label className="block text-xs font-semibold text-slate-300 mb-1">
      ID Object
    </label>

    <div className="w-full px-3 py-2 text-xs bg-slate-900 border border-slate-800 rounded-xl text-slate-500 font-mono truncate">
      {editingObjectId || 'Otomatis dibuat'}
    </div>
  </div>
</div>
          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">Alamat / Catatan Lokasi</label>
            <input
              type="text"
              value={newObjAddress}
              onChange={(e) => setNewObjAddress(e.target.value)}
              placeholder="cth. Jl. Merdeka No. 12"
              className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
            />
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
            <button
              type="button"
              onClick={() => setIsObjectModalOpen(false)}
              className="px-4 py-2 text-xs font-medium text-slate-400 hover:text-white bg-slate-800 rounded-xl cursor-pointer"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={submittingObject}
              className="px-4 py-2 text-xs font-medium text-white bg-cyan-600 hover:bg-cyan-500 rounded-xl shadow transition cursor-pointer disabled:opacity-50"
            >
             {submittingObject
  ? 'Menyimpan...'
  : editingObjectId
    ? 'Simpan Perubahan Node'
    : 'Simpan Objek ke Database'}
            </button>
          </div>
	  <div>
  <label className="block text-xs font-semibold text-slate-300 mb-1">
    Catatan
  </label>

  <textarea
    value={newObjNotes}
    onChange={(e) => setNewObjNotes(e.target.value)}
    rows={3}
    placeholder="Catatan teknis, patokan lokasi, kondisi node, dll."
    className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-500 resize-none"
  />
</div>
        </form>
      </Modal>

      {/* Modal: Simpan Kabel Rute Polyline */}
      <Modal isOpen={isCableModalOpen} onClose={() => setIsCableModalOpen(false)} title="Simpan Jalur Kabel Fiber Optik">
        <form onSubmit={handleSaveCable} className="space-y-4">
          <div className="p-3 bg-slate-950 rounded-xl border border-slate-800 text-xs text-slate-300 flex items-center justify-between">
            <span>Jumlah Titik Koordinat (Vertices):</span>
            <span className="font-mono text-amber-400 font-bold">{drawnVertices.length} Titik</span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Kode Kabel *</label>
              <input
                type="text"
                required
                value={cableCode}
                onChange={(e) => setCableCode(e.target.value)}
                placeholder="cth. FEEDER-01-SEC-A / DROP-01"
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 uppercase font-mono focus:outline-none focus:border-cyan-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Nama Kabel *</label>
              <input
                type="text"
                required
                value={cableName}
                onChange={(e) => setCableName(e.target.value)}
                placeholder="cth. Kabel Distribusi ODC-01 ke ODP-05"
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Tipe Kabel</label>
              <select
                value={cableType}
                onChange={(e) => {
                  const t = e.target.value as CableType;
                  setCableType(t);
                  if (t === 'DROPCORE') setCableColor('#f59e0b');
                  else if (t === 'FEEDER') setCableColor('#8b5cf6');
                  else setCableColor('#3b82f6');
                }}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              >
                <option value="DISTRIBUTION">Distribusi (Jaringan ODC ke ODP)</option>
                <option value="DROPCORE">Dropcore (ODP ke Rumah Pelanggan)</option>
                <option value="FEEDER">Feeder (Kabel Utama OLT ke ODC)</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Jumlah Core</label>
              <input
                type="number"
                value={cableCores}
                onChange={(e) => setCableCores(e.target.value)}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Warna Garis</label>
              <input
                type="color"
                value={cableColor}
                onChange={(e) => setCableColor(e.target.value)}
                className="w-full h-9 bg-slate-950 border border-slate-800 rounded-xl px-1 py-1 cursor-pointer"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Titik Awal (Start Object) *</label>
              <select
                required
                value={cableStartId}
                onChange={(e) => setCableStartId(e.target.value)}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              >
                <option value="">Pilih Titik Awal...</option>
                {objects.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.code} - {o.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Titik Akhir (End Object) *</label>
              <select
                required
                value={cableEndId}
                onChange={(e) => setCableEndId(e.target.value)}
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-950 border border-slate-800 rounded-xl text-slate-100 focus:outline-none focus:border-cyan-500"
              >
                <option value="">Pilih Titik Akhir...</option>
                {objects.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.code} - {o.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-slate-800">
            <button
              type="button"
              onClick={() => setIsCableModalOpen(false)}
              className="px-4 py-2 text-xs font-medium text-slate-400 hover:text-white bg-slate-800 rounded-xl cursor-pointer"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={submittingCable}
              className="px-4 py-2 text-xs font-medium text-white bg-cyan-600 hover:bg-cyan-500 rounded-xl shadow transition cursor-pointer disabled:opacity-50"
            >
              {submittingCable ? 'Menyimpan...' : 'Simpan Jalur Kabel'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
};
