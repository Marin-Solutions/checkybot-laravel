import { useLocalSearchParams } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';

export default function ProblemsScreen() {
  const params = useLocalSearchParams<{ projectUuid: string; groupUuid?: string; monitorUuids?: string; states?: string }>();
  return (
    <View style={styles.page} testID="problems-screen">
      <Text accessibilityRole="header" style={styles.title}>Filtered problems</Text>
      <Text testID="filter-project" style={styles.row}>Project {params.projectUuid}</Text>
      {params.groupUuid ? <Text testID="filter-group" style={styles.row}>Group {params.groupUuid}</Text> : null}
      {params.monitorUuids ? <Text testID="filter-monitors" style={styles.row}>Monitors {params.monitorUuids}</Text> : null}
      {params.states ? <Text testID="filter-states" style={styles.row}>States {params.states}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  page: { backgroundColor: '#f8fafc', flex: 1, minHeight: '100%', padding: 28 },
  title: { color: '#0f172a', fontSize: 28, fontWeight: '800', marginBottom: 24 },
  row: { backgroundColor: '#ffffff', borderRadius: 10, color: '#334155', marginBottom: 10, padding: 14 },
});
