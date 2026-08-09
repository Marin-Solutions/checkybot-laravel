export interface WidgetBridge {
  openProblems(): Promise<void>;
}

export type WidgetBridgeLoader = () => Promise<WidgetBridge>;

export async function loadIosWidgetBridge(platform: 'ios' | 'android' | 'web', load: WidgetBridgeLoader): Promise<WidgetBridge | null> {
  if (platform !== 'ios') return null;
  return load();
}
