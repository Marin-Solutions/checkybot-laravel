import { Link } from 'expo-router';
import { Text, View } from 'react-native';

export default function NotFound() {
  return <View><Text>Page not found.</Text><Link href="/">Return to status</Link></View>;
}
